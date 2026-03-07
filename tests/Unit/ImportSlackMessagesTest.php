<?php

namespace Tests\Unit;

use App\Importers\Slack\ImportSlackMessages;
use App\Support\ImportCheckpointStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportSlackMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_channels_does_not_crash(): void
    {
        Http::fake([
            'slack.com/api/conversations.list*' => Http::response([
                'ok'                => true,
                'channels'          => [],
                'response_metadata' => ['next_cursor' => ''],
            ], 200),
        ]);

        $logs = [];
        $log  = function (string $msg, string $level = 'info') use (&$logs): void {
            $logs[] = $msg;
        };

        $importer = new ImportSlackMessages(
            system:   'test-slack',
            botToken: 'xoxb-fake-token',
            mode:     'full',
        );

        $importer->run($log);

        $this->assertTrue(
            collect($logs)->contains(fn ($l) => str_contains($l, 'No channels found')),
            'Expected a "No channels found" log line. Got: ' . implode(' | ', $logs)
        );
    }

    public function test_checkpoint_saved_after_full_run(): void
    {
        $ts = '1704067200.000100';

        Http::fake([
            'slack.com/api/conversations.list*' => Http::response([
                'ok'                => true,
                'channels'          => [
                    [
                        'id'          => 'C01234ABCDE',
                        'name'        => 'general',
                        'is_private'  => false,
                        'is_member'   => true,
                        'topic'       => ['value' => ''],
                        'purpose'     => ['value' => ''],
                        'num_members' => 5,
                    ],
                ],
                'response_metadata' => ['next_cursor' => ''],
            ], 200),

            // History — one message, then empty cursor signals end
            'slack.com/api/conversations.history*' => Http::sequence()
                ->push([
                    'ok'       => true,
                    'messages' => [
                        [
                            'type'    => 'message',
                            'ts'      => $ts,
                            'user'    => 'U01234ABCDE',
                            'text'    => 'Hello Slack',
                            'files'   => [],
                        ],
                    ],
                    'has_more'          => false,
                    'response_metadata' => ['next_cursor' => ''],
                ], 200),
        ]);

        $importer = new ImportSlackMessages(
            system:   'test-slack',
            botToken: 'xoxb-fake-token',
            mode:     'full',
        );

        $importer->run(fn () => null);

        $checkpoint = app(ImportCheckpointStore::class)->get('slack|messages|test-slack|C01234ABCDE');

        $this->assertNotNull($checkpoint, 'Checkpoint should be saved after full run.');
        $this->assertEquals($ts, $checkpoint['latest_ts']);

        $this->assertDatabaseHas('source_slack_messages', [
            'system_slug' => 'test-slack',
            'channel_id'  => 'C01234ABCDE',
            'ts'          => $ts,
        ]);
    }
}
