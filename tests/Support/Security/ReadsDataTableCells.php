<?php

namespace Tests\Support\Security;

use App\Models\User;

/**
 * A merchant's (or the operator's) list as the browser receives it: the AJAX leg of a yajra DataTable, decoded, one cell at a time.  P1-SEC-80.
 *
 * Why this and not a page: a DataTable page is an empty shell, and the rows arrive as JSON whose cells are inserted into the page as HTML. A cell in `rawColumns()` is inserted as the string
 * the server wrote, so the cell is the unit that matters; it is what DetectsActiveMarkup is asked about. The envelope is checked first (`draw` and `data`), because the HTML leg answers a
 * request without them with a view, and every assertion after that would then be measuring nothing.
 */
trait ReadsDataTableCells
{
    /**
     * @param  array<string,mixed>  $extraQuery  filters the list's own screen sends
     * @return array<string,mixed>  the decoded envelope
     */
    private function listEnvelope(User $actor, string $path, array $extraQuery = []): array
    {
        $query = array_merge(['draw' => 1, 'start' => 0, 'length' => 25, 'search[value]' => '', 'search[regex]' => 'false'], $extraQuery);

        $response = $this->actingAs($actor)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson($path . '?' . http_build_query($query));

        $response->assertOk();
        $payload = $response->json();

        $this->assertIsArray($payload, 'the list answered something that is not JSON');
        $this->assertArrayHasKey('draw', $payload, 'yajra served the HTML leg, not the AJAX leg: every assertion that follows would measure nothing');
        $this->assertArrayHasKey('data', $payload);

        return $payload;
    }

    /** the cells of every row, DECODED (the browser's view), one string. Not json_encode: that escapes `"` and a needle would then miss a cell that really contains it. */
    private function cellsOf(array $envelope): string
    {
        $cells = [];

        foreach ($envelope['data'] as $row) {
            foreach ((array) $row as $value) {
                $cells[] = is_scalar($value) ? (string) $value : json_encode($value);
            }
        }

        return implode("\n", $cells);
    }
}
