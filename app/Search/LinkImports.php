<?php

namespace App\Search;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;
use App\Filament\Pages\ListingView;
use App\Jobs\PollListingImport;
use App\Models\User;
use App\RealEstate\LinkImport;
use App\RealEstate\RealEstateClient;

/**
 * Ссылка myhome / ss.ge (02_Решения, 29.10): Real Estate сам решает, объявление это или выдача.
 * Выдача становится обычным поиском в «Моих поисках», объявление — карточкой (сразу или после сборщика).
 */
class LinkImports
{
    /** Адрес не длиннее этого: выдача с фильтрами сайта длинная, но не бесконечная. */
    public const MAX_LENGTH = 2000;

    /** Сайты и их хосты: ссылку с другого сайта не отправляем вовсе. */
    private const HOSTS = ['myhome_ge' => 'myhome.ge', 'ss_ge' => 'ss.ge'];

    public function __construct(
        private readonly RealEstateClient $client,
        private readonly SiteSearches $searches,
    ) {}

    /** Сайт ссылки (`myhome_ge`, `ss_ge`) по хосту, с поддоменами (www., home.); чужой — null. */
    public static function source(string $url): ?string
    {
        $url = trim($url);
        // Две ссылки или ссылка с текстом в одном поле — не одна ссылка.
        if (preg_match('/\s/u', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::HOSTS as $source => $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Карточка объявления, которое человек сам прислал ссылкой. Чужое направление открывается и так
     * (как выдача поиска по ссылке, 27.10): подпись `link` связывает человека и объявление.
     */
    public static function cardUrl(User $by, int $listingId): string
    {
        return ListingView::getUrl(['listing' => $listingId, 'link' => self::signature($by, $listingId)]);
    }

    public static function sentBy(User $viewer, int $listingId, mixed $signature): bool
    {
        // Без направлений человек объявления не открывает вовсе — и подпись не помогает.
        return is_string($signature) && OrgAccess::directions($viewer) !== [] && hash_equals(self::signature($viewer, $listingId), $signature);
    }

    private static function signature(User $by, int $listingId): string
    {
        return hash_hmac('sha256', 'link-import:'.$by->getKey().':'.$listingId, (string) config('app.key'));
    }

    /**
     * Исключения клиента (RealEstate*, CrmForbidden) пробрасываем — окно покажет их человеку.
     */
    public function start(User $by, string $url): LinkStart
    {
        $url = trim($url);
        if (OrgAccess::directions($by) === []) {
            throw new CrmForbidden('У человека нет направлений для поиска');
        }
        if (self::source($url) === null || mb_strlen($url) > self::MAX_LENGTH) {
            throw new CrmForbidden('Ссылка не с myhome или ss.ge');
        }

        // Та же ссылка, пока поиск по ней идёт или свежий, — тот же поиск, без нового POST (как у формы).
        $import = null;
        $search = $this->searches->startWith($by, sha1('link:'.$url), function () use ($url, &$import): ?array {
            $import = $this->client->startImport($url);

            return $import->search !== null ? [$import->search, $this->filters($import, $url)] : null;
        });
        if ($search !== null) {
            return LinkStart::search($search);
        }

        /** @var LinkImport $import */
        if (! $import->ready() && ! $import->finished() && $import->id !== null) {
            PollListingImport::start($import->id, $by->getKey(), $url);
        }

        return LinkStart::listing($import);
    }

    /**
     * Фильтры записи поиска: ссылка и сделка, которую Real Estate увидел в ней (направление результата).
     * Сделки нет или она не продажа/аренда — без неё: тогда результат в направлениях агента.
     *
     * @return array<string, mixed>
     */
    private function filters(LinkImport $import, string $url): array
    {
        $deal = null;
        if ($import->filters['deal_type_id'] !== null) {
            // Справочник не отдался — исключение: поиск без сделки навсегда ушёл бы в направления агента.
            // Real Estate держит выдачу 30 минут, повтор возьмёт ту же.
            $slug = array_search($import->filters['deal_type_id'], $this->client->dealTypeIds(), true);
            $deal = is_string($slug) ? Direction::tryFrom($slug) : null;
        }

        return array_filter([
            'link' => $import->url !== '' ? $import->url : $url,
            // Что вставил человек: по ней ключ поиска, «Повторить» шлёт её же и попадает в тот же ключ.
            'link_sent' => $url,
            'link_source' => $import->source !== '' ? $import->source : null,
            'deal' => $deal?->value,
            'property_type_id' => $import->filters['property_type_id'],
            'city_id' => $import->filters['city_id'],
        ], fn (mixed $v): bool => $v !== null);
    }
}
