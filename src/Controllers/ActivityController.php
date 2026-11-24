<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;

/** Audit log and automatic file backups. Administrators only. */
final class ActivityController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Acl::MOD_SETTINGS, Acl::CHANGE);
        $q = $this->request->query('q');

        return $this->page('activity/index', [
            'entries' => $this->services->audit()->recent(500, $q),
            'q' => $q,
        ], 'Activity log', 'activity');
    }

    public function backups(): Response
    {
        $this->authorize(Acl::MOD_SETTINGS, Acl::CHANGE);

        return $this->page('activity/backups', [
            'backups' => $this->services->files()->backups(),
            'folder' => $this->services->config->path('Common', 'BackupFolder', './data/backup'),
            'keep' => $this->services->config->int('Common', 'BackupCount', 30),
        ], 'Backups', 'backups');
    }

    public function download(string $file): Response
    {
        $this->authorize(Acl::MOD_SETTINGS, Acl::CHANGE);
        foreach ($this->services->files()->backups() as $backup) {
            if ($backup['file'] === $file) {
                $this->audit('backup.download', $file);

                return new Response(200, (string) file_get_contents($backup['path']), [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Content-Disposition' => Response::attachment($backup['file']),
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        throw HttpException::notFound();
    }
}
