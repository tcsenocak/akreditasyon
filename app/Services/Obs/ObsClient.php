<?php

namespace App\Services\Obs;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Atatürk Üniversitesi OBS Bologna (Ders Bilgi Paketi) herkese açık uç noktaları.
 */
class ObsClient
{
    private float $lastRequestAt = 0.0;

    /**
     * Birim ağacı: fakülteler (path = ''), bölümler ("767"), programlar ("767/1770").
     *
     * @return list<array{id: string, label: string, bt_id: string|null}>
     */
    public function units(string $path = ''): array
    {
        $type = config('intibak.obs.unit_type');
        $body = $this->http()->get("/moduller/islem/eobs/getirBirimlerByBtId/{$type}/".ltrim($path, '/'))->throw()->body();

        return array_map(fn (array $u) => ['id' => (string) $u['id'], 'label' => (string) $u['label'], 'bt_id' => $u['bt_id'] ?? null], $this->json($body));
    }

    /**
     * Program sayfasındaki giriş yılı seçenekleri.
     *
     * @return array<int, string> ay_id => "2024 - 2025 Akademik Yılı"
     */
    public function academicYears(int $programId, int $unitType = 33): array
    {
        $html = $this->http()->get("/moduller/dbp/eobs/birimDetay/{$programId}/{$unitType}")->throw()->body();
        preg_match('/<select[^>]+id="ay_id"[^>]*>(.*?)<\/select>/s', $html, $select);
        preg_match_all('/<option value="(\d+)"[^>]*>([^<]+)<\/option>/', $select[1] ?? '', $m, PREG_SET_ORDER);
        $years = [];
        foreach ($m as $option) {
            $years[(int) $option[1]] = trim($option[2]);
        }

        return $years;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function curriculum(int $programId, int $yearId): array
    {
        $body = $this->http()->asForm()->post('/moduller/islem/eobs/getirProgramMufredat', ['ay_id' => $yearId, 'program_id' => $programId])->throw()->body();

        return $this->json($body);
    }

    /**
     * Dersin açıldığı en son dönemin kimliği (ap_id) ve etiketi.
     *
     * @return array{ap_id: int, label: string}|null
     */
    public function latestOffering(int $courseId, int $programId): ?array
    {
        $html = $this->http()->get("/moduller/dbp/eobs/dersDetay/{$courseId}/{$programId}")->throw()->body();
        if (! preg_match('/<select[^>]+id="ap_id"[^>]*>(.*?)<\/select>/s', $html, $select)) {
            return null;
        }
        if (! preg_match('/<option value="(\d+)">(.*?)<\/option>/s', $select[1], $m)) {
            return null;
        }

        return ['ap_id' => (int) $m[1], 'label' => trim(preg_replace('/\s+/u', ' ', strip_tags($m[2])) ?? '')];
    }

    /**
     * @return array<string, mixed>
     */
    public function courseInfo(int $offeringId, int $programId): array
    {
        $body = $this->http()->asForm()->post('/moduller/islem/eobs/getirDersBilgiler', ['ap_id' => $offeringId, 'program_id' => $programId])->throw()->body();

        return $this->json($body);
    }

    private function http(): PendingRequest
    {
        $delay = (int) config('intibak.obs.request_delay_ms', 250) / 1000;
        $wait = $this->lastRequestAt + $delay - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);

        return Http::baseUrl(config('intibak.obs.base_url'))
            ->timeout(60)
            ->retry(3, 1500, throw: false)
            ->withHeaders(['User-Agent' => 'AU-Muafiyet-Intibak/1.0']);
    }

    /**
     * @return array<mixed>
     */
    private function json(string $body): array
    {
        $body = trim($body, "\xEF\xBB\xBF \t\n\r");
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
