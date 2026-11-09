<?php

use App\Services\Enquiries\EnquiryCreationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Separate processes exercise the shared creation path against one disposable DB.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.busy_timeout' => 5000]);
DB::purge('sqlite');
file_put_contents($argv[2].'.ready', 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent creation test barrier timed out.');
    }
    usleep(1000);
}
// SQLite has no SELECT FOR UPDATE. Reserve its writer lock before any reads
// to emulate the conversation lock used by the application on PostgreSQL.
$enquiry = DB::transaction(function () use ($argv) {
    DB::table('ai_conversations')->where('id', (int) $argv[4])
        ->update(['status' => DB::raw('status')]);

    return app(EnquiryCreationService::class)->getOrCreate((int) $argv[4], [
        'practice_area' => 'wills_and_probate', 'workflow_key' => 'will_enquiry_v1',
    ]);
});
echo json_encode(['id' => $enquiry->id]);
