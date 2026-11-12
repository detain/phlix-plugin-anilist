<?php

/**
 * Consequence tests for ANL-5: the admin-supplied AniList username is
 * guarded at the settings parse boundary and scrubbed + capped before it
 * is projected into a log context.
 *
 * Laws pinned here:
 * 1. AniListSettings::fromArray() trims the username (paste whitespace) and
 *    refuses — fails CLOSED to "" (unconfigured) — any value that is not
 *    short printable ASCII: control bytes, CR/LF log-forging payloads,
 *    bidi overrides, interior spaces, and floods never get stored;
 * 2. every sync log line that carries the username (start, completed,
 *    per-status failure, pagination cap) projects it through the scrub +
 *    cap helpers, so even a direct-construction consumer that skips the
 *    parse boundary cannot forge or flood a log line.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Plugins\Metadata\AniList\AniListSettings;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class AniListUsernameGuardTest extends TestCase
{
    // ------------------------------------------------------------------
    // Law 1: parse-boundary guard.
    // ------------------------------------------------------------------

    public function test_pasted_username_is_trimmed_of_paste_whitespace(): void
    {
        $settings = AniListSettings::fromArray(['access_token' => 'tok', 'username' => "  animefan\n"]);

        $this->assertSame('animefan', $settings->username, 'paste-time whitespace is trimmed, not fatal');
        $this->assertTrue($settings->isConfigured());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dirtyUsernames(): array
    {
        return [
            'CRLF log forge' => ["joe\r\n[CRITICAL] forged line"],
            'lone LF' => ["joe\nforged"],
            'NUL byte' => ["jo\x00e"],
            'ANSI escape' => ["\x1B[31mjoe"],
            'bidi override' => ["joe\u{202E}eoj"],
            'interior space' => ['joe smith'],
            'non-ASCII' => ['jöe'],
            'flood' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('dirtyUsernames')]
    public function test_a_dirty_username_never_stores(string $dirty): void
    {
        $settings = AniListSettings::fromArray(['access_token' => 'tok', 'username' => $dirty]);

        $this->assertSame('', $settings->username, 'a dirty username fails closed to unset');
        $this->assertFalse($settings->isConfigured(), 'a dirty username must not count as configured');
    }

    public function test_a_clean_username_round_trips_unchanged(): void
    {
        $name = str_repeat('a', 63) . 'Z';
        foreach (['animefan', 'Joe_Huss-99', $name] as $clean) {
            $settings = AniListSettings::fromArray(['username' => $clean]);

            $this->assertSame($clean, $settings->username, 'the guard must not alter valid usernames');
            $this->assertSame($clean, $settings->toArray()['username']);
        }
    }

    public function test_a_non_string_username_falls_back_to_unset(): void
    {
        foreach ([null, 42, ['joe'], true] as $raw) {
            $this->assertSame('', AniListSettings::fromArray(['username' => $raw])->username);
        }
    }

    // ------------------------------------------------------------------
    // Law 2: sync log projections are scrubbed + capped.
    // ------------------------------------------------------------------

    public function test_sync_start_and_completed_logs_scrub_and_cap_the_username(): void
    {
        $dirty = "joe\r\n[CRITICAL] forged\u{202E}" . str_repeat('x', 400);
        $logger = new UsernameGuardRecordingLogger();
        $plugin = $this->makePlugin($dirty, $logger, $this->listApi(fail: false));

        $this->invoke($plugin, 'syncWatchlistFromAniList');

        foreach (['AniList: starting watchlist sync', 'AniList: watchlist sync completed'] as $message) {
            $this->assertCleanProjection($logger->contextFor($message)['username']);
        }
    }

    public function test_status_list_failure_log_scrubs_and_caps_the_username(): void
    {
        $dirty = "joe\nforged\x1B[31m" . str_repeat('y', 400);
        $logger = new UsernameGuardRecordingLogger();
        $plugin = $this->makePlugin($dirty, $logger, $this->listApi(fail: true));

        $this->invoke($plugin, 'syncStatusList', $dirty, 'CURRENT');

        $this->assertCleanProjection($logger->contextFor('AniList: failed to sync status list')['username']);
    }

    public function test_pagination_cap_log_scrubs_and_caps_the_username(): void
    {
        $dirty = "joe\r\nforged\u{2066}" . str_repeat('z', 400);
        $logger = new UsernameGuardRecordingLogger();
        $plugin = $this->makePlugin($dirty, $logger, $this->listApi(fail: false, fullPages: true));

        $this->invoke($plugin, 'syncStatusList', $dirty, 'CURRENT');

        $this->assertCleanProjection(
            $logger->contextFor('AniList: status list hit the pagination cap')['username']
        );
    }

    public function test_a_clean_username_logs_verbatim(): void
    {
        $logger = new UsernameGuardRecordingLogger();
        $plugin = $this->makePlugin('animefan', $logger, $this->listApi(fail: false));

        $this->invoke($plugin, 'syncWatchlistFromAniList');

        $this->assertSame('animefan', $logger->contextFor('AniList: starting watchlist sync')['username']);
    }

    private function assertCleanProjection(mixed $logged): void
    {
        $this->assertIsString($logged);
        $this->assertDoesNotMatchRegularExpression(
            '/[\x00-\x1F\x7F]/',
            $logged,
            'no control byte (CR/LF/ESC/NUL) may reach a log line'
        );
        $this->assertStringNotContainsString("\u{202E}", $logged, 'bidi override must not reach the log stream');
        $this->assertStringNotContainsString("\u{2066}", $logged, 'bidi isolate must not reach the log stream');
        $this->assertLessThanOrEqual(211, mb_strlen($logged), 'username projection = cap + [truncated] marker');
        $this->assertStringStartsWith('joe', $logged, 'the projection must stay recognizable');
    }

    private function makePlugin(string $username, UsernameGuardRecordingLogger $logger, AniListApi $api): AniListPlugin
    {
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();

        // Direct construction deliberately skips the fromArray() parse
        // boundary: the log sites must hold on their own.
        $this->setProp($plugin, 'settings', new AniListSettings(accessToken: 'tok', username: $username));
        $this->setProp($plugin, 'enabled', true);
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'logger', $logger);
        $this->setProp($plugin, 'itemRepository', new ItemRepository());

        return $plugin;
    }

    private function listApi(bool $fail, bool $fullPages = false): AniListApi
    {
        return new class ($fail, $fullPages) extends AniListApi {
            public function __construct(private bool $fail, private bool $fullPages)
            {
                $http = new class implements HttpClientInterface {
                    public function post(string $url, array $data, array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }

                    public function get(string $url, array $params = [], array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }
                };
                parent::__construct($http, 'tok', new NullLogger());
            }

            /**
             * @return list<array<string, mixed>>
             */
            public function getUserAnimeList(string $username, ?string $status = null, int $page = 1, int $perPage = 50): array
            {
                if ($this->fail) {
                    throw new AniListApiException('list fetch failed');
                }

                // A full page of media-less entries keeps the walk going
                // until the page cap without touching the repository.
                return $this->fullPages ? array_fill(0, $perPage, ['media' => null]) : [];
            }
        };
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        for ($class = new \ReflectionClass($obj); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                $class->getProperty($name)->setValue($obj, $value);

                return;
            }
        }

        throw new \RuntimeException("Property {$name} not found on " . $obj::class);
    }

    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($obj, $method))->invoke($obj, ...$args);
    }
}

/**
 * Minimal PSR-3 recording logger local to this suite (no cross-file
 * dependency on another test file's helper class).
 */
final class UsernameGuardRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<mixed>
     */
    public function contextFor(string $needle): array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['context'];
            }
        }

        throw new \RuntimeException("no log record containing '{$needle}'");
    }
}
