<?php

namespace App\Domain\Leads;

use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LeadsExporter — the CSV export action behind the merchant Leads list (A6).
 *
 * Account-scoped: leads are read through the BelongsToAccount global scope inside
 * Tenant::run($account), optionally narrowed to one site. A merchant can therefore
 * only ever export their OWN account's leads; another account's leads can never land
 * in the file (a forgotten filter fails closed). No withoutGlobalScopes().
 *
 * The column set is the A6 leads table contract plus the lead's lifecycle timestamps.
 * rows() returns the exact data array (header + rows) so a test can assert the content
 * without parsing HTTP; download() wraps it in a streamed text/csv response so a large
 * export never buffers the whole file in memory.
 */
final class LeadsExporter
{
    // === CONSTANTS ===
    public const FILENAME_PREFIX = 'leads';

    // The frozen column order (A6 + lifecycle). Header keys are stable tokens; the
    // admin layer maps them to __() headers — the FILE itself ships these tokens so a
    // re-import / downstream parser binds to a fixed contract.
    public const COLUMNS = [
        'full_name',
        'email',
        'phone',
        'status',
        'registered',
        'generations_used',
        'created_at',
        'last_seen_at',
    ];

    private const CONTENT_TYPE = 'text/csv';
    private const BOOL_YES = 'yes';
    private const BOOL_NO = 'no';

    /**
     * The export data: a header row followed by one row per lead (newest first).
     * Account-scoped; pass a $site to narrow to that site's leads (the site must
     * belong to the account or it yields nothing — the global scope guarantees it).
     *
     * @return array<int,array<int,string>>
     */
    public function rows(Account $account, ?Site $site = null): array
    {
        $leads = $this->query($account, $site);

        $out = [self::COLUMNS];

        foreach ($leads as $lead) {
            $out[] = $this->toRow($lead);
        }

        return $out;
    }

    /**
     * A streamed text/csv response of the account's leads (A6 export action). The
     * filename carries the account id + date so a downloaded file is self-identifying.
     */
    public function download(Account $account, ?Site $site = null): StreamedResponse
    {
        $rows = $this->rows($account, $site);
        $filename = $this->filename($account, $site);

        $response = new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="'.$filename.'"',
        );

        return $response;
    }

    /**
     * The account-scoped lead query, newest first. Runs inside the bound tenant so
     * the BelongsToAccount scope isolates it; the optional site filter is an extra
     * narrow within the account, never a way out of it.
     *
     * @return Collection<int,EndUser>
     */
    private function query(Account $account, ?Site $site): Collection
    {
        return Tenant::run($account, function () use ($site): Collection {
            $query = EndUser::query()->orderByDesc('created_at');

            if ($site !== null) {
                $query->where('site_id', $site->getKey());
            }

            return $query->get();
        });
    }

    /**
     * One CSV row for a lead in the frozen COLUMNS order. Null fields become empty
     * strings; booleans become yes/no; timestamps are ISO-8601.
     *
     * @return array<int,string>
     */
    private function toRow(EndUser $lead): array
    {
        return [
            (string) ($lead->full_name ?? ''),
            (string) ($lead->email ?? ''),
            (string) ($lead->phone ?? ''),
            (string) $lead->status,
            $lead->isRegistered() ? self::BOOL_YES : self::BOOL_NO,
            (string) ((int) $lead->generations_used),
            $lead->created_at?->toIso8601String() ?? '',
            $lead->last_seen_at?->toIso8601String() ?? '',
        ];
    }

    /** Self-identifying filename: leads-account-{id}-{date}[-site-{id}].csv */
    private function filename(Account $account, ?Site $site): string
    {
        $parts = [self::FILENAME_PREFIX, 'account-'.$account->getKey()];

        if ($site !== null) {
            $parts[] = 'site-'.$site->getKey();
        }

        $parts[] = now()->format('Ymd');

        return implode('-', $parts).'.csv';
    }
}
