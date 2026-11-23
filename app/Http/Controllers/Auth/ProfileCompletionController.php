<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Kabupaten;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProfileCompletionController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        // If active, redirect to dashboard
        if ($user->status === 'active') {
            return redirect('/dashboard');
        }

        $kabupatens = Kabupaten::withoutIndonesia()->orderBy('kode_kab', 'asc')->get();

        // If user has OTP pending (and phone verified is null), show OTP step
        $showOtpStep = !empty($user->otp_code) && is_null($user->no_hp_verified_at);

        return view('auth.complete-profile', compact('user', 'kabupatens', 'showOtpStep'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        abort_if($user->status === 'active', 403);
        if (!empty($user->otp_code) && is_null($user->no_hp_verified_at)) {
            return back()->with('error', 'Anda sudah mengirimkan kode OTP.');
        }
        $request->validate(['no_hp' => 'required|string']);
        $rawNoHp = $request->no_hp;

        // Hapus semua selain angka
        $noHp = preg_replace('/[^0-9]/', '', $rawNoHp);

        // Jika diawali 0 → ganti 62
        if (str_starts_with($noHp, '0')) {
            $noHp = '62' . substr($noHp, 1);
        }
        $request->merge([
            'no_hp' => $noHp
        ]);
        $request->validate([
            'name' => 'required|string|max:255',
            'no_hp' => [
                'required',
                'regex:/^62[0-9]{8,13}$/',
                Rule::unique('users', 'no_hp')->ignore(auth()->id()),
            ],
            'kabupaten_id' => 'required|exists:tb_kabupaten,id',
            'team' => 'required|string',
        ], [
            'name.required' => 'Nama Lengkap wajib diisi.',
            'no_hp.required' => 'Nomor WhatsApp wajib diisi.',
            'no_hp.regex' => 'Nomor WhatsApp harus diawali 62 dan berisi 10 sampai 15 digit.',
            'no_hp.unique' => 'Nomor WhatsApp sudah digunakan oleh pengguna lain.',
            'kabupaten_id.required' => 'Silakan pilih Kabupaten/Kota.',
            'team.required' => 'Silakan pilih Unit Kerja.',
        ]);

        // Profile completion always requires phone verification and approval.
        $user->update([
            'name' => $request->name,
            'no_hp' => $request->no_hp,
            'kabupaten_id' => $request->kabupaten_id,
            'team' => $request->team,
            'role' => 'user', // Ensure default role
            'status' => 'pending',
            'no_hp_verified_at' => null,
        ]);

        // Generate and Send OTP
        if (!$this->generateAndSendOtp($user)) {
            return redirect('/complete-profile')->with('error', 'Kode OTP gagal dikirim. Silakan coba kirim ulang.');
        }


        return redirect('/complete-profile')->with('success', 'Kode OTP telah dikirim ke nomor WhatsApp Anda.');
    }

    public function verifyOtp(Request $request)
    {

        $request->validate([
            'otp_code' => 'required|string|size:6',
        ], [
            'otp_code.required' => 'Kode OTP wajib diisi.',
            'otp_code.size' => 'Kode OTP harus 6 digit.',
        ]);

        $user = Auth::user();
        abort_if($user->status === 'active', 403);
        /** @var \App\Models\User $user */

        if (!$user->otp_code || !$user->otp_expires_at) {
            return back()->with('error', 'Tidak ada permintaan OTP yang aktif. Silakan minta kode baru.');
        }

        if (\Carbon\Carbon::now()->gt($user->otp_expires_at)) {
            return back()->with('error', 'Kode OTP telah kedaluwarsa. Silakan minta kode baru.');
        }

        if (!hash_equals((string) $user->otp_code, $request->otp_code)) {
            return back()->with('error', 'Kode OTP salah. Silakan coba lagi.');
        }

        // OTP Valid
        $user->update([
            'no_hp_verified_at' => now(),
            'otp_code' => null,
            'otp_expires_at' => null,
            'status' => 'pending' // Now set status to pending for Admin approval
        ]);

        return redirect('/complete-profile')->with('success', 'Nomor HP berhasil diverifikasi. Akun Anda sekarang menunggu persetujuan Admin.');
    }

    public function resendOtp()
    {

        $user = Auth::user();
        abort_if($user->status === 'active', 403);
        if ($user->no_hp_verified_at) {
            return back()->with('error', 'Nomor WhatsApp sudah diverifikasi.');
        }
        if ($user->otp_expires_at && \Carbon\Carbon::now()->lt($user->otp_expires_at)) {
            $remaining = \Carbon\Carbon::now()->diffInSeconds($user->otp_expires_at);
            return back()->with('error', 'Silakan tunggu ' . gmdate('i:s', $remaining) . ' sebelum mengirim ulang kode OTP.');
        }

        if (empty($user->no_hp)) {
            return back()->with('error', 'Nomor HP belum terdaftar. Silakan isi data profil kembali.');
        }

        if (!$this->generateAndSendOtp($user)) {
            return back()->with('error', 'Kode OTP gagal dikirim. Silakan coba lagi.');
        }

        return back()->with('success', 'Kode OTP baru telah dikirim ke nomor WhatsApp Anda.');
    }

    public function resetNumber()
    {

        $user = Auth::user();
        abort_if($user->status === 'active', 403);

        // Check if OTP is still valid (not expired)
        if ($user->otp_expires_at && \Carbon\Carbon::now()->lt($user->otp_expires_at)) {
            $remaining = \Carbon\Carbon::now()->diffInSeconds($user->otp_expires_at);
            return back()->with('error', 'Harap tunggu ' . gmdate('i:s', $remaining) . ' sebelum mengganti nomor.');
        }

        // Allow reset
        $user->update([
            'otp_code' => null,
            'otp_expires_at' => null
        ]);

        return redirect()->route('complete-profile');
    }

    private function generateAndSendOtp($user)
    {
        $otp = (string) random_int(100000, 999999);
        $expiresAt = \Carbon\Carbon::now()->addMinutes(5);

        $user->update([
            'otp_code' => $otp,
            'otp_expires_at' => $expiresAt
        ]);

        $message = "Kode OTP verifikasi SISOKA Anda: {$otp}. Berlaku selama 5 menit. Jangan bagikan kode ini.";
        if (!\App\Helpers\WhatsAppHelper::kirimPesanWhatsApp($user->no_hp, $message)) {
            // Invalidate failed deliveries and allow an immediate retry.
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);
            return false;
        }

        return true;

    }
}
