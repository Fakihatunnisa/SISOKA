<?php

namespace Tests\Feature;

use App\Models\Desa;
use App\Models\DescanPeriode;
use App\Models\DescanPeserta;
use App\Models\Dimensi;
use App\Models\Fenomena;
use App\Models\IndikatorBidang;
use App\Models\IndikatorDimensi;
use App\Models\IndikatorMakro;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\NilaiIndikatorMakro;
use App\Models\NilaiKemiskinan;
use App\Models\PeriodeIndikator;
use App\Models\RhPerubahanHeader;
use App\Models\RhTahun;
use App\Models\User;
use App\Models\VariabelKemiskinan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function userFor(string $code = '6101', string $status = 'active'): User
    {
        $kabupaten = Kabupaten::firstOrCreate(['kode_kab' => $code], ['nama_kabupaten' => $code]);

        return User::factory()->create([
            'kabupaten_id' => $kabupaten->id, 'status' => $status, 'role' => 'user',
            'no_hp' => '6281234567890', 'no_hp_verified_at' => now(),
        ]);
    }

    private function peserta(User $owner): DescanPeserta
    {
        $kecamatan = Kecamatan::create(['kabupaten_id' => $owner->kabupaten_id, 'kode_kecamatan' => '001', 'nama_kecamatan' => 'Test']);
        $desa = Desa::create(['kecamatan_id' => $kecamatan->id, 'kode_desa' => '001', 'nama_desa' => 'Test']);
        $periode = DescanPeriode::firstOrCreate(['tahun' => 2026], ['is_active' => true]);

        return DescanPeserta::create([
            'desa_id' => $desa->id, 'kecamatan_id' => $kecamatan->id,
            'kabupaten_id' => $owner->kabupaten_id, 'periode_id' => $periode->id, 'created_by' => $owner->id,
        ]);
    }

    public function test_active_user_cannot_promote_their_region_through_profile_completion(): void
    {
        $user = $this->userFor();
        $province = Kabupaten::create(['kode_kab' => '6100', 'nama_kabupaten' => 'Province']);
        Http::preventStrayRequests();
        $this->actingAs($user)->post(route('complete-profile.update'), [
            'name' => 'Changed', 'no_hp' => $user->no_hp, 'team' => 'Social', 'kabupaten_id' => $province->id,
        ])->assertForbidden();
        $this->assertSame('6101', $user->fresh()->kabupaten->kode_kab);
        $this->assertSame('active', $user->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_desa_progress_and_deletion_are_scoped_to_the_users_region(): void
    {
        $user = $this->userFor();
        $other = $this->userFor('6102');
        $peserta = $this->peserta($other);
        $this->actingAs($user)->post(route('desa-cantik.progress.store', $peserta->id), ['action_type' => 'draf'])->assertForbidden();
        $this->delete(route('desa-cantik.peserta.destroy', $peserta->id))->assertForbidden();
        $this->get(route('desa-cantik.progress.detail', $peserta->id))->assertForbidden();
        $this->assertModelExists($peserta);
        $this->actingAs($other)->post(route('desa-cantik.progress.store', $peserta->id), ['action_type' => 'draf'])->assertSessionHas('success');
        $this->delete(route('desa-cantik.peserta.destroy', $peserta->id))->assertSessionHas('success');
        $this->assertModelMissing($peserta);
    }

    public function test_desa_registration_rejects_mismatched_region_hierarchy(): void
    {
        $user = $this->userFor();
        $otherPeserta = $this->peserta($this->userFor('6102'));
        $this->actingAs($user)->post(route('desa-cantik.peserta.store'), [
            'periode_id' => $otherPeserta->periode_id, 'kabupaten_id' => $user->kabupaten_id,
            'kecamatan_id' => $otherPeserta->kecamatan_id, 'desa_id' => $otherPeserta->desa_id,
        ])->assertSessionHasErrors('kecamatan_id');

        $own = $this->peserta($user);
        $this->post(route('desa-cantik.peserta.store'), [
            'periode_id' => $own->periode_id, 'kabupaten_id' => $user->kabupaten_id,
            'kecamatan_id' => $own->kecamatan_id, 'desa_id' => $otherPeserta->desa_id,
        ])->assertSessionHasErrors('desa_id');
    }

    public function test_otp_is_delivered_privately_and_can_be_resent_after_expiry(): void
    {
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.url' => 'https://wa.example/send']);
        Http::fake(['https://wa.example/*' => Http::response(['status' => true])]);
        $user = $this->userFor(status: 'pending');
        $user->update(['no_hp_verified_at' => null]);
        $this->actingAs($user)->post(route('complete-profile.update'), [
            'name' => $user->name, 'no_hp' => $user->no_hp, 'kabupaten_id' => $user->kabupaten_id, 'team' => 'Social',
        ])->assertSessionHas('success');
        $otp = $user->fresh()->otp_code;
        $this->assertNotEmpty($otp);
        $this->get(route('complete-profile'))->assertDontSee($otp);
        Http::assertSent(fn ($request) => $request['number'] === $user->no_hp && str_contains($request['message'], $otp));
        $user->update(['otp_expires_at' => now()->subMinute()]);
        $this->post(route('complete-profile.resend-otp'))->assertSessionHas('success');
        Http::assertSentCount(2);
        $this->post(route('complete-profile.verify-otp'), ['otp_code' => $user->fresh()->otp_code])->assertSessionHas('success');
        $this->assertNotNull($user->fresh()->no_hp_verified_at);
    }

    public function test_failed_otp_delivery_cannot_leave_a_usable_code_or_allow_admin_approval(): void
    {
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.url' => 'https://wa.example/send']);
        Http::fake(['https://wa.example/*' => Http::response(['status' => false])]);
        $user = $this->userFor(status: 'pending');
        $user->update(['no_hp_verified_at' => null]);
        $this->actingAs($user)->post(route('complete-profile.resend-otp'))->assertSessionHas('error');
        $this->assertNull($user->fresh()->otp_code);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.approve', $user->id))->assertSessionHas('error');
        $this->assertSame('pending', $user->fresh()->status);
    }

    public function test_cross_year_rh_header_is_rejected_before_any_writes(): void
    {
        $user = $this->userFor();
        $active = RhTahun::create(['tahun' => 2026, 'is_active' => true]);
        $old = RhTahun::create(['tahun' => 2025, 'is_active' => false]);
        $header = RhPerubahanHeader::create(['rh_tahun_id' => $old->id, 'tanggal_perubahan' => '2025-02-01', 'label' => 'Old', 'user_id_add' => $user->id]);
        $this->actingAs($user)->post(route('rh-nilai.save'), [
            'rh_tahun_id' => $active->id, 'kabupaten_id' => $user->kabupaten_id,
            'revision' => [$header->id => [1 => ['min' => 1, 'max' => 2]]],
        ])->assertSessionHasErrors('revision');
        $this->assertDatabaseCount('tb_rh_perubahan_detail', 0);

        $validHeader = RhPerubahanHeader::create(['rh_tahun_id' => $active->id, 'tanggal_perubahan' => '2026-02-01', 'label' => 'Current', 'user_id_add' => $user->id]);
        $category = \App\Models\KategoriKomoditas::create(['nama_kategori' => 'Test']);
        $commodity = \App\Models\Komoditas::create(['kategori_id' => $category->id, 'nama_komoditas' => 'Test', 'batas_selisih_harga' => 100]);
        $this->post(route('rh-nilai.save'), [
            'rh_tahun_id' => $active->id, 'kabupaten_id' => $user->kabupaten_id,
            'revision' => [$validHeader->id => [$commodity->id => ['min' => '100', 'max' => '110', 'alasan' => null]]],
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('tb_rh_perubahan_detail', [
            'rh_tahun_id' => $active->id, 'rh_perubahan_header_id' => $validHeader->id, 'min_edit' => 100, 'max_edit' => 110,
        ]);
    }

    public function test_macro_locks_and_number_validation_preserve_existing_data(): void
    {
        $user = $this->userFor('6100');
        $period = PeriodeIndikator::create(['tahun' => 2026, 'is_active' => true]);
        $bidang = IndikatorBidang::create(['nama_bidang' => 'Test']);
        $makro = IndikatorMakro::create(['indikator_bidang_id' => $bidang->id, 'nama_indikator' => 'Test', 'is_active' => true]);
        $dimension = Dimensi::create(['nama_dimensi' => 'Test']);
        $relation = IndikatorDimensi::create(['indikator_makro_id' => $makro->id, 'dimensi_id' => $dimension->id, 'is_active' => true]);
        $payload = ['periode_indikator_id' => $period->id, 'indikator_makro_id' => $makro->id, 'nilai' => [$user->kabupaten_id => [$relation->id => '0,123']]];
        $this->actingAs($user)->post(route('indikator-makro.store-nilai'), $payload)->assertSessionHas('success');
        $value = NilaiIndikatorMakro::firstOrFail();
        $this->assertEquals(0.123, $value->nilai);

        foreach ([$period, $makro, $relation] as $locked) {
            $locked->update(['is_active' => false]);
            $this->post(route('indikator-makro.store-nilai'), $payload)->assertForbidden();
            $locked->update(['is_active' => true]);
        }
        $payload['nilai'][$user->kabupaten_id][$relation->id] = 'abc';
        $this->post(route('indikator-makro.store-nilai'), $payload)->assertSessionHasErrors();
        $this->assertEquals(0.123, $value->fresh()->nilai);

        $payload['nilai'][$user->kabupaten_id] = [$relation->id => null, 999999 => '123'];
        $this->post(route('indikator-makro.store-nilai'), $payload)->assertForbidden();
        $this->assertEquals(0.123, $value->fresh()->nilai);

        $value->update(['nilai' => 1.234]);
        $this->get(route('indikator-makro.input-nilai', [
            'periode_indikator_id' => $period->id, 'indikator_makro_id' => $makro->id,
        ]))->assertOk()->assertSee('value="1.2340"', false);
        $payload['nilai'][$user->kabupaten_id] = [$relation->id => '1.2340'];
        $this->post(route('indikator-makro.store-nilai'), $payload)->assertSessionHas('success');
        $this->assertEquals(1.234, $value->fresh()->nilai);
    }

    public function test_invalid_poverty_rows_do_not_replace_or_shift_existing_percentiles(): void
    {
        $user = $this->userFor('6100');
        $variable = VariabelKemiskinan::create(['nama_variabel' => 'Test', 'bulan' => 3, 'tahun' => 2026]);
        $value = NilaiKemiskinan::create(['kabupaten_id' => $user->kabupaten_id, 'variabel_kemiskinan_id' => $variable->id, 'persentil' => 1, 'nilai' => 99]);
        foreach (["100\nN/A\n300", "100\n\n300"] as $raw) {
            $this->actingAs($user)->post(route('poverty-data.store'), [
                'kabupaten_id' => $user->kabupaten_id, 'variabel_id' => $variable->id, 'raw_data' => $raw,
            ])->assertSessionHasErrors('raw_data');
            $this->assertEquals(99, $value->fresh()->nilai);
            $this->assertDatabaseCount('tb_nilai_kemiskinan', 1);
        }
    }

    public function test_guests_cannot_read_unverified_fenomena_but_can_read_published_records(): void
    {
        $owner = $this->userFor();
        $fenomena = Fenomena::factory()->create(['status_verifikasi' => 'P', 'created_by' => $owner->id]);
        $this->get(route('fenomena.show', $fenomena->id))->assertNotFound();
        $fenomena->update(['status_verifikasi' => 'T']);
        $this->get(route('fenomena.show', $fenomena->id))->assertNotFound();
        $fenomena->update(['status_verifikasi' => 'Y']);
        $this->get(route('fenomena.show', $fenomena->id))->assertOk();
        $fenomena->update(['status_verifikasi' => 'P']);
        $this->actingAs($owner)->get(route('fenomena.show', $fenomena->id))->assertOk();
        $this->actingAs($this->userFor('6102'))->get(route('fenomena.show', $fenomena->id))->assertNotFound();
        $this->actingAs($this->userFor('6100'))->get(route('fenomena.show', $fenomena->id))->assertOk();
    }
}
