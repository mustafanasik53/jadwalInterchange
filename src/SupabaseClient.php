<?php
/**
 * Thin wrapper around the Supabase REST (PostgREST) and Auth (GoTrue) HTTP
 * APIs using native cURL. No third-party SDK/Composer dependency.
 *
 * Data calls (get/post/patch/delete) always use the SERVICE ROLE key and are
 * only ever made from PHP server-side code. Because the service key bypasses
 * Row Level Security entirely, every caller in this app MUST independently
 * check $_SESSION['role'] / $_SESSION['user_id'] before using this client —
 * this class does not and cannot enforce authorization for you.
 */

declare(strict_types=1);

final class SupabaseException extends RuntimeException
{
}

final class SupabaseClient
{
    private string $url;
    private string $anonKey;
    private string $serviceKey;

    public function __construct(string $url, string $anonKey, string $serviceKey)
    {
        $this->url = rtrim($url, '/');
        $this->anonKey = $anonKey;
        $this->serviceKey = $serviceKey;
    }

    public static function fromEnv(): self
    {
        $url = env('SUPABASE_URL', '');
        $anon = env('SUPABASE_ANON_KEY', '');
        $service = env('SUPABASE_SERVICE_KEY', '');

        if ($url === '' || $anon === '' || $service === '') {
            throw new SupabaseException('Supabase configuration is missing. Check your .env file.');
        }

        return new self($url, $anon, $service);
    }

    // -------------------------------------------------------------------
    // PostgREST data helpers (rest/v1/<table>) — always use service_role.
    // -------------------------------------------------------------------

    /**
     * @param array<string,string> $query Query-string parameters, e.g.
     *   ['select' => '*', 'status' => 'eq.pending', 'order' => 'created_at.desc']
     * @return array<int,array<string,mixed>>
     */
    public function get(string $table, array $query = []): array
    {
        $path = '/rest/v1/' . ltrim($table, '/');
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $result = $this->request('GET', $path, null, $this->dataHeaders());
        return is_array($result) ? $result : [];
    }

    /**
     * Insert one or more rows. Returns the inserted rows (Prefer: return=representation).
     *
     * @param array<string,mixed>|array<int,array<string,mixed>> $data
     * @return array<int,array<string,mixed>>
     */
    public function post(string $table, array $data, array $query = []): array
    {
        $path = '/rest/v1/' . ltrim($table, '/');
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = $this->dataHeaders();
        $headers[] = 'Prefer: return=representation';

        $result = $this->request('POST', $path, $data, $headers);
        return is_array($result) ? $result : [];
    }

    /**
     * Update rows matching the given filters.
     *
     * @param array<string,string> $filters e.g. ['id' => 'eq.<uuid>']
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    public function patch(string $table, array $filters, array $data): array
    {
        $path = '/rest/v1/' . ltrim($table, '/');
        if ($filters !== []) {
            $path .= '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = $this->dataHeaders();
        $headers[] = 'Prefer: return=representation';

        $result = $this->request('PATCH', $path, $data, $headers);
        return is_array($result) ? $result : [];
    }

    /**
     * Convenience: fetch a single row (first match or null).
     *
     * @param array<string,string> $query
     * @return array<string,mixed>|null
     */
    public function getOne(string $table, array $query = []): ?array
    {
        $rows = $this->get($table, $query);
        return $rows[0] ?? null;
    }

    private function dataHeaders(): array
    {
        return [
            'apikey: ' . $this->serviceKey,
            'Authorization: Bearer ' . $this->serviceKey,
            'Content-Type: application/json',
        ];
    }

    // -------------------------------------------------------------------
    // Auth (GoTrue) helpers
    // -------------------------------------------------------------------

    /**
     * Password grant login. Returns the decoded JSON response (contains
     * access_token, user, etc.) or throws SupabaseException on failure.
     *
     * @return array<string,mixed>
     */
    public function signInWithPassword(string $email, string $password): array
    {
        $path = '/auth/v1/token?grant_type=password';
        $headers = [
            'apikey: ' . $this->anonKey,
            'Content-Type: application/json',
        ];

        $result = $this->request('POST', $path, ['email' => $email, 'password' => $password], $headers);

        if (!is_array($result)) {
            throw new SupabaseException('Unexpected response from authentication server.');
        }

        return $result;
    }

    /**
     * Admin create-user (service role). Used by scripts/create_user.php only.
     *
     * @return array<string,mixed>
     */
    public function adminCreateUser(string $email, string $password, bool $emailConfirm = true): array
    {
        $path = '/auth/v1/admin/users';
        $headers = [
            'apikey: ' . $this->serviceKey,
            'Authorization: Bearer ' . $this->serviceKey,
            'Content-Type: application/json',
        ];

        $result = $this->request('POST', $path, [
            'email' => $email,
            'password' => $password,
            'email_confirm' => $emailConfirm,
        ], $headers);

        if (!is_array($result)) {
            throw new SupabaseException('Unexpected response creating auth user.');
        }

        return $result;
    }

    // -------------------------------------------------------------------
    // Low-level cURL request
    // -------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $body
     * @param array<int,string> $headers
     * @return array<mixed>|null
     */
    private function request(string $method, string $path, ?array $body, array $headers)
    {
        $ch = curl_init($this->url . $path);
        if ($ch === false) {
            throw new SupabaseException('Could not initialize network request.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new SupabaseException('Network error talking to Supabase: ' . $error);
        }

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
        }

        if ($status < 200 || $status >= 300) {
            $message = 'Supabase request failed (HTTP ' . $status . ')';
            if (is_array($decoded)) {
                $message = (string) ($decoded['error_description'] ?? $decoded['msg'] ?? $decoded['message'] ?? $message);
            }
            throw new SupabaseException($message);
        }

        return $decoded;
    }
}
