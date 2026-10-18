<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Http\Controllers;

use AlfaSix\AlfakitOps\OpsPlugin;
use AlfaSix\AlfakitOps\Pages\LogFile;
use AlfaSix\AlfakitOps\Support\LogFiles;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sends a log file. The route sits in the panel (signed-in users only), needs a valid,
 * unexpired signature, re-checks the plugin's authorisation and the download ability, and
 * only serves files `LogFiles::resolve()` accepts (no path traversal, no symbolic links
 * out of the log directory).
 */
class DownloadLogController
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_unless(LogFile::can(OpsPlugin::ABILITY_DOWNLOAD_LOGS), 403);

        $file = $request->route('file');
        $path = is_string($file) ? LogFiles::resolve($file) : null;

        abort_if($path === null, 404);

        return response()->download($path, basename($path), ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
