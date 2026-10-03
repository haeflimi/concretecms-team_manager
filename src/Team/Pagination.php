<?php
namespace TeamManager\Team;

/**
 * Renders page links for TeamRepository::paginate() results, markup works with Bootstrap 4 and 5.
 */
class Pagination
{
    const PARAM = 'tm_page';

    public static function getCurrentPage(): int
    {
        return max(1, (int) app('request')->query->get(self::PARAM));
    }

    /**
     * @param array $result result of TeamRepository::paginate()
     * @param string $baseURL page URL without query string
     * @param array $query query parameters to keep, e.g. keywords and pool filter
     */
    public static function render(array $result, string $baseURL, array $query = []): string
    {
        if ($result['pages'] <= 1) {
            return '';
        }
        $link = function (int $page) use ($baseURL, $query) {
            $query[self::PARAM] = $page;

            return h($baseURL . '?' . http_build_query(array_filter($query, function ($v) {
                return $v !== '' && $v !== null;
            })));
        };

        $html = '<nav><ul class="pagination">';
        for ($page = 1; $page <= $result['pages']; $page++) {
            // keep the list short: first, last and two pages around the current one
            if ($page !== 1 && $page !== $result['pages'] && abs($page - $result['page']) > 2) {
                if ($page === 2 || $page === $result['pages'] - 1) {
                    $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
                }
                continue;
            }
            $active = $page === $result['page'] ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $link($page) . '">' . $page . '</a></li>';
        }

        return $html . '</ul></nav>';
    }
}
