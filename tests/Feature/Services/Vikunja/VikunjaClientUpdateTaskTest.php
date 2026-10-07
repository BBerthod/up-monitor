<?php

namespace Tests\Feature\Services\Vikunja;

use App\Services\Vikunja\VikunjaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /tasks/{id} replaces the model, so updateTask() echoes the task back.
 * Vikunja returns `related_tasks` on read but rejects it on write with a 400,
 * which silently broke every card closure and left resolved cards collecting a
 * "Résolu" comment every 15 minutes.
 */
class VikunjaClientUpdateTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
    }

    public function test_update_task_never_echoes_read_only_relations(): void
    {
        Http::fake([
            'https://vikunja.test/api/v1/tasks/161' => Http::sequence()
                ->push(['id' => 161, 'title' => 'Card', 'done' => false, 'related_tasks' => ['subtask' => []]])
                ->push(['id' => 161, 'title' => 'Card', 'done' => true]),
        ]);

        app(VikunjaClient::class)->markDone(161);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            return ! array_key_exists('related_tasks', $request->data())
                && $request->data()['done'] === true
                && $request->data()['title'] === 'Card';
        });
    }
}
