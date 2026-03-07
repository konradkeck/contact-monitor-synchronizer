<?php

namespace Tests\Unit;

use App\Importers\Discord\ImportDiscordMessages;
use App\Support\ImportCheckpointStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportDiscordMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_guilds_does_not_crash(): void
    {
        Http::fake([
            'discord.com/api/v10/users/@me/guilds' => Http::response([], 200),
        ]);

        $logs = [];
        $log  = function (string $msg, string $level = 'info') use (&$logs): void {
            $logs[] = $msg;
        };

        $importer = new ImportDiscordMessages(
            system:   'test-discord',
            botToken: 'fake-token',
            mode:     'full',
        );

        $importer->run($log);

        $this->assertTrue(
            collect($logs)->contains(fn ($l) => str_contains($l, 'No guilds found')),
            'Expected a "No guilds found" log line. Got: ' . implode(' | ', $logs)
        );
    }

    public function test_checkpoint_saved_after_full_run(): void
    {
        $messageId = '1234567890123456789';

        Http::fake([
            'discord.com/api/v10/users/@me/guilds' => Http::response([
                ['id' => '111111111111111111', 'name' => 'Test Guild'],
            ], 200),

            'discord.com/api/v10/guilds/111111111111111111/channels' => Http::response([
                ['id' => '222222222222222222', 'name' => 'general', 'type' => 0],
            ], 200),

            // Messages endpoint — return one message, then empty (signals end of pagination)
            'discord.com/api/v10/channels/222222222222222222/messages?limit=100' => Http::sequence()
                ->push([
                    [
                        'id'               => $messageId,
                        'content'          => 'Hello world',
                        'author'           => ['id' => '333333333333333333', 'username' => 'testuser'],
                        'timestamp'        => '2024-01-01T00:00:00+00:00',
                        'edited_timestamp' => null,
                        'attachments'      => [],
                        'type'             => 0,
                    ],
                ], 200)
                ->push([], 200), // Empty = no more pages
        ]);

        $importer = new ImportDiscordMessages(
            system:   'test-discord',
            botToken: 'fake-token',
            mode:     'full',
        );

        $importer->run(fn () => null);

        $checkpoint = app(ImportCheckpointStore::class)->get('discord|messages|test-discord|222222222222222222');

        $this->assertNotNull($checkpoint, 'Checkpoint should be saved after full run.');
        $this->assertEquals($messageId, $checkpoint['last_message_id']);

        $this->assertDatabaseHas('source_discord_messages', [
            'system_slug' => 'test-discord',
            'channel_id'  => '222222222222222222',
            'message_id'  => $messageId,
        ]);
    }
}
