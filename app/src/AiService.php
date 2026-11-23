<?php
declare(strict_types=1);

namespace App;

use Anthropic\Client;

/** Odhad nutričných hodnôt pomocou Claude API (text, fotka jedla, screenshot tréningu). */
final class AiService
{
    private const SYSTEM_FOOD = <<<'TXT'
Si skúsený nutričný poradca so znalosťou slovenskej a českej kuchyne, bežných reštauračných porcií
a nutričných tabuliek (napr. Kalorické tabuľky, USDA). Tvojou úlohou je odhadnúť, čo užívateľ zjedol,
a vypočítať energetickú hodnotu a makroživiny.

Pravidlá:
- Názvy položiek píš po slovensky, stručne (napr. "Vyprážaný bravčový rezeň", "Varené zemiaky").
- Jedlo rozdeľ na zložky, ak sú zreteľne oddelené (mäso, príloha, šalát, omáčka, nápoj). Polievku alebo
  zmiešané jedlo (rizoto, guláš) uveď ako jednu položku.
- amount_g je odhad hmotnosti zjedenej porcie v gramoch (nápoje v ml ≈ g).
- kcal, protein_g, carbs_g (všetky sacharidy), fat_g a fiber_g sú hodnoty pre CELÚ odhadnutú porciu, nie na 100 g.
- Ak užívateľ uvedie množstvo alebo gramáž, rešpektuj ju. Popis od užívateľa má prednosť pred tým, čo vidíš.
- Pri položke z jedálneho lístka predpokladaj bežnú porciu v slovenskej reštaurácii (ak je uvedená gramáž
  mäsa, napr. "150 g", použi ju a pridaj typickú prílohu, iba ak je súčasťou jedla).
- Zohľadni spôsob prípravy (vyprážanie, maslo, olej, omáčky) – skryté tuky výrazne zvyšujú kalórie.
- confidence: "high" ak je jedlo aj množstvo jasné, "medium" pri bežnom odhade, "low" ak si nie si istý.
- Ak na fotke ani v texte nie je žiadne jedlo alebo nápoj, vráť prázdny zoznam items a vysvetli to v comment.
- comment: jedna krátka veta po slovensky (napr. čo si predpokladal). Žiadne dlhé texty.
TXT;

    private const SYSTEM_ACTIVITY = <<<'TXT'
Čítaš screenshoty z aplikácií na sledovanie aktivít (Garmin Connect, Strava, Apple Fitness a pod.).
Zisti druh aktivity a spálené kalórie.
- kcal: uprednostni "Aktívne kalórie" / "Active calories". Ak sú uvedené iba "Kalórie" / "Calories"
  pre aktivitu, použi tie. Ak ide o denný prehľad, použi aktívne kalórie za deň.
- name: krátky slovenský názov aktivity (napr. "Beh", "Cyklistika", "Posilňovňa", "Plávanie").
- duration_min: trvanie v minútach, ak je uvedené, inak 0.
- date: dátum aktivity vo formáte RRRR-MM-DD, ak je na obrázku jednoznačne uvedený, inak prázdny reťazec.
- is_activity: false, ak obrázok neobsahuje údaje o aktivite.
- comment: jedna krátka veta po slovensky.
TXT;

    private static function foodSchema(): array
    {
        $num = ['type' => 'number'];
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'amount_g' => $num,
                            'kcal' => $num,
                            'protein_g' => $num,
                            'carbs_g' => $num,
                            'fat_g' => $num,
                            'fiber_g' => $num,
                            'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                        'required' => ['name', 'amount_g', 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['items', 'comment'],
            'additionalProperties' => false,
        ];
    }

    private static function activitySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_activity' => ['type' => 'boolean'],
                'name' => ['type' => 'string'],
                'kcal' => ['type' => 'number'],
                'duration_min' => ['type' => 'number'],
                'date' => ['type' => 'string'],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['is_activity', 'name', 'kcal', 'duration_min', 'date', 'comment'],
            'additionalProperties' => false,
        ];
    }

    public static function isEnabled(): bool
    {
        return trim((string) Config::get('anthropic_api_key')) !== '';
    }

    private static function client(): Client
    {
        if (!self::isEnabled()) {
            throw new HttpError(503, 'AI nie je nastavená – administrátor musí doplniť Anthropic API kľúč do config.php');
        }
        $base = trim((string) Config::get('anthropic_base_url', ''));
        return new Client(apiKey: (string) Config::get('anthropic_api_key'), baseUrl: $base !== '' ? $base : null);
    }

    /** Odhad z textového popisu. */
    public static function analyzeText(string $text): array
    {
        $content = [[
            'type' => 'text',
            'text' => "Užívateľ popisuje, čo zjedol:\n\"\"\"\n" . $text . "\n\"\"\"\nOdhadni položky a ich nutričné hodnoty.",
        ]];
        return self::normalizeFood(self::call(self::SYSTEM_FOOD, $content, self::foodSchema()));
    }

    /** Odhad z fotky jedla alebo z fotky položky jedálneho lístka. */
    public static function analyzePhoto(string $imageData, string $mime, string $kind, string $description): array
    {
        $intro = $kind === 'menu'
            ? 'Na obrázku je položka (alebo časť) z jedálneho lístka. Užívateľ si objednal a zjedol túto položku. Prečítaj názov, prípadnú gramáž a odhadni nutričné hodnoty bežnej porcie.'
            : 'Na fotke je jedlo, ktoré užívateľ zjedol. Rozpoznaj jedlo, odhadni veľkosť porcie a nutričné hodnoty.';
        if (trim($description) !== '') {
            $intro .= "\nPopis od užívateľa: \"" . trim($description) . '"';
        }
        $content = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($imageData)]],
            ['type' => 'text', 'text' => $intro],
        ];
        return self::normalizeFood(self::call(self::SYSTEM_FOOD, $content, self::foodSchema()));
    }

    /** Prečítanie tréningu zo screenshotu (napr. Garmin Connect). */
    public static function analyzeActivity(string $imageData, string $mime): array
    {
        $content = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($imageData)]],
            ['type' => 'text', 'text' => 'Prečítaj z tohto screenshotu údaje o aktivite.'],
        ];
        $r = self::call(self::SYSTEM_ACTIVITY, $content, self::activitySchema());
        return [
            'is_activity' => (bool) ($r['is_activity'] ?? false),
            'name' => mb_substr(trim((string) ($r['name'] ?? '')) ?: 'Tréning', 0, 120),
            'kcal' => max(0, round((float) ($r['kcal'] ?? 0))),
            'duration_min' => max(0, (int) round((float) ($r['duration_min'] ?? 0))),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? '')) ? $r['date'] : '',
            'comment' => (string) ($r['comment'] ?? ''),
        ];
    }

    private static function call(string $system, array $content, array $schema): array
    {
        @set_time_limit(150);
        $client = self::client();
        try {
            $msg = $client->beta->messages->create(
                model: (string) Config::get('anthropic_model', 'claude-opus-5-5'),
                maxTokens: 8000,
                system: $system,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: [
                    'effort' => (string) Config::get('anthropic_effort', 'low'),
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                // Ak model požiadavku odmietne, server ju automaticky skúsi na náhradnom modeli.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (\Anthropic\Core\Exceptions\RateLimitException $e) {
            throw new HttpError(503, 'AI je momentálne preťažená, skúste o chvíľu');
        } catch (\Anthropic\Core\Exceptions\AuthenticationException $e) {
            throw new HttpError(503, 'Neplatný Anthropic API kľúč – kontaktujte administrátora');
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            self::log('API error: ' . $e->getMessage());
            throw new HttpError(502, 'AI služba vrátila chybu, skúste to znova');
        } catch (\Anthropic\Core\Exceptions\APIConnectionException $e) {
            self::log('Connection error: ' . $e->getMessage());
            throw new HttpError(502, 'Nepodarilo sa spojiť s AI službou');
        }

        if ($msg->stopReason === 'refusal') {
            throw new HttpError(422, 'AI túto požiadavku nespracovala, skúste inú fotku alebo popis');
        }
        if ($msg->stopReason === 'max_tokens') {
            throw new HttpError(502, 'AI odpoveď bola neúplná, skúste to znova');
        }
        $text = '';
        foreach ($msg->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            self::log('Invalid JSON: ' . mb_substr($text, 0, 500));
            throw new HttpError(502, 'AI vrátila nečitateľnú odpoveď, skúste to znova');
        }
        return $data;
    }

    private static function normalizeFood(array $r): array
    {
        $items = [];
        foreach (($r['items'] ?? []) as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[] = [
                'name' => mb_substr($name, 0, 200),
                'amount_g' => max(0, round((float) ($it['amount_g'] ?? 0))),
                'kcal' => max(0, round((float) ($it['kcal'] ?? 0))),
                'protein' => max(0, round((float) ($it['protein_g'] ?? 0), 1)),
                'carbs' => max(0, round((float) ($it['carbs_g'] ?? 0), 1)),
                'fat' => max(0, round((float) ($it['fat_g'] ?? 0), 1)),
                'fiber' => max(0, round((float) ($it['fiber_g'] ?? 0), 1)),
                'confidence' => in_array($it['confidence'] ?? '', ['low', 'medium', 'high'], true) ? $it['confidence'] : 'medium',
            ];
        }
        return ['items' => $items, 'comment' => (string) ($r['comment'] ?? '')];
    }

    private static function log(string $msg): void
    {
        @file_put_contents(Config::storage('logs/ai.log'), date('c') . ' ' . $msg . "\n", FILE_APPEND);
    }
}
