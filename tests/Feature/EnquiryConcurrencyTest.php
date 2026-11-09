<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnquiryConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_creators_return_one_enquiry(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'enquiry-concurrency-');
        $gate = $database.'.go';
        $processes = [];
        $original = config('database.default');
        try {
            config(['database.connections.enquiry_concurrency' => [
                'driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
            ]]);
            Artisan::call('migrate', ['--database' => 'enquiry_concurrency', '--force' => true]);
            config(['database.default' => 'enquiry_concurrency']);
            $conversation = AiConversation::create(['uuid' => (string) Str::uuid()]);
            foreach ([0, 1] as $worker) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/enquiry-create-worker.php'), $database, $database.'.'.$worker, $gate, (string) $conversation->id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 10;
            while (! file_exists($database.'.0.ready') || ! file_exists($database.'.1.ready')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not reach the concurrent creation barrier.');
                }
                usleep(1000);
            }
            touch($gate);
            $ids = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error.$output);
                $decoded = json_decode($output, true);
                $this->assertIsArray($decoded, $output.$error);
                $ids[] = $decoded['id'];
            }
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, DB::table('enquiries')->count());
        } finally {
            foreach ($processes as [$process]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            DB::purge('enquiry_concurrency');
            config(['database.default' => $original]);
            foreach (glob($database.'*') as $file) {
                unlink($file);
            }
        }
    }
}
