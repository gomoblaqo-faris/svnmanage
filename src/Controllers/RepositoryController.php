<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Authz\AuthzFile;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;
use SvnManage\Svn\Repository;
use SvnManage\Svn\RepositoryService;

final class RepositoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::VIEW);
        $service = $this->service();
        $q = $this->request->query('q');
        $locations = [];
        foreach ($service->parents() as $parent) {
            try {
                $repos = $this->filter($service->repositories($parent), $q, static fn (Repository $r): string => $r->name);
                $repos = array_values(array_filter($repos, fn (Repository $r): bool => $this->auth->canSeeRepository($r->name)));
                $locations[] = ['parent' => $parent, 'repos' => $repos, 'error' => null];
            } catch (\Throwable $e) {
                $locations[] = ['parent' => $parent, 'repos' => [], 'error' => $this->errorText($e)];
            }
        }

        return $this->page('repositories/index', [
            'locations' => $locations,
            'q' => $q,
            'canDelete' => $this->canDelete(),
            'canDump' => $this->canDump(),
            'canAdd' => $this->services->hasRepoEdit() && $this->auth->can(Acl::MOD_REPO, Acl::ADD),
            'canDeletePaths' => $this->services->hasAccessPathEdit(),
        ], 'Repositories', 'repositories');
    }

    public function create(): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::ADD);
        $this->requireActive($this->services->hasRepoEdit());

        return $this->page('repositories/create', [
            'parents' => $this->service()->parents(),
            'canCreatePath' => $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::ADD),
            'old' => $this->ctx->session->get('old_repo', []),
        ], 'New repository', 'repositories.new');
    }

    public function store(): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::ADD);
        $this->requireActive($this->services->hasRepoEdit());
        $r = $this->request;
        $this->ctx->session->set('old_repo', ['name' => $r->input('reponame'), 'project' => $r->input('projectname')]);
        try {
            $repo = $this->service()->create(
                (int) $r->input('pi', '0'),
                $r->input('reponame'),
                $r->input('repotype', 'fsfs'),
                $r->input('repostructuretype', 'none'),
                $r->input('projectname'),
                $this->auth->username,
            );
        } catch (\Throwable $e) {
            $this->fail($e);

            return $this->redirect('/repositories/new');
        }
        $this->ctx->session->remove('old_repo');
        $this->audit('repository.create', $repo->name, ['type' => $r->input('repotype'), 'structure' => $r->input('repostructuretype')]);
        $this->success('The repository %0 has been created successfully', [$repo->name]);

        if ($r->input('accesspathcreate') === '1' && $this->canCreateAccessPath($repo->name . ':/')) {
            try {
                $this->services->authz()->update(static fn (AuthzFile $a): AuthzFile => $a->hasAccessPath($repo->name . ':/') ? $a : $a->withAccessPath($repo->name . ':/'));
                $this->audit('accesspath.create', $repo->name . ':/');
                $this->success('Created AccessPath "%0" successfully.', [$repo->name . ':/']);
            } catch (\Throwable $e) {
                $this->fail($e);
            }
        }

        return $this->redirect('/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name));
    }

    public function delete(): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::DELETE);
        if (!$this->canDelete()) {
            throw HttpException::inactive('Deleting repositories is disabled (GUI.RepositoryDeleteEnabled).');
        }
        $names = $this->request->inputList('selected_repos');
        if ($names === []) {
            $this->error('You have to select at least one repository.');

            return $this->back('/repositories');
        }
        $parentId = (int) $this->request->input('pi', '0');
        $removePaths = $this->request->input('delete_ap') === '1' && $this->canDeleteAccessPaths();
        $service = $this->service();
        $this->each($names, function (string $name) use ($service, $parentId, $removePaths): void {
            $repo = $this->find((string) $parentId, $name);
            $service->delete($repo);
            $this->audit('repository.delete', $repo->name);
            $this->success('The repository %0 has been deleted.', [$repo->name]);
            if ($removePaths) {
                $paths = array_values(array_filter(
                    $this->services->authz()->load()->accessPathsOfRepository($repo->name),
                    fn (string $p): bool => $this->auth->canManagePath($p),
                ));
                $this->services->authz()->update(static function (AuthzFile $a) use ($paths): AuthzFile {
                    foreach ($paths as $p) {
                        $a = $a->withoutAccessPath($p);
                    }

                    return $a;
                });
                $this->services->roles()->update(static function ($roles) use ($paths) {
                    foreach ($paths as $p) {
                        $roles = $roles->withoutPathEverywhere($p);
                    }

                    return $roles;
                });
                foreach ($paths as $p) {
                    $this->success('Removed Access-Path "%0"', [$p]);
                }
            }
        });

        return $this->redirect('/repositories');
    }

    public function show(string $parent, string $name): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::VIEW);
        $repo = $this->find($parent, $name);
        $info = null;
        $log = [];

        $error = null;
        try {
            $info = $this->service()->info($repo, true);
            $log = $this->auth->canReadHistory($repo->name) ? $this->service()->log($repo, '', 15) : null;
        } catch (\Throwable $e) {
            $error = $this->errorText($e);
        }
        $paths = [];
        if ($this->services->hasAccessPathView() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::VIEW)) {
            try {
                $authz = $this->services->authz()->load();
                foreach ($this->auth->filterPaths($authz->accessPathsOfRepository($repo->name)) as $p) {
                    $paths[] = ['path' => $p, 'rules' => count($authz->rules($p))];
                }
            } catch (\Throwable $e) {
                $error ??= $this->errorText($e);
            }
        }

        return $this->page('repositories/show', [
            'repo' => $repo,
            'parent' => $this->service()->parent($repo->parentId),
            'info' => $info,
            'log' => $log,
            'paths' => $paths,
            'error' => $error,
            'canDump' => $this->canDump(),
            'canLoad' => $this->canLoad(),
            'canDelete' => $this->canDelete(),
            'canAddPath' => $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::ADD),
            'maxUpload' => self::maxUpload(),
        ], $repo->name, 'repositories');
    }

    public function browse(string $parent, string $name): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::VIEW);
        $repo = $this->find($parent, $name);
        $path = RepositoryService::cleanRelative($this->request->query('path'));
        if (!$this->auth->canBrowse($repo->name, $path)) {
            throw HttpException::forbidden();
        }
        $entries = [];
        $error = null;
        try {
            $entries = array_values(array_filter(
                $this->service()->browse($repo, $path),
                fn ($e): bool => $this->auth->canBrowse($repo->name, ($path === '' ? '' : $path . '/') . $e->name),
            ));
        } catch (\Throwable $e) {
            $error = $this->errorText($e);
        }
        $accessPaths = [];
        if ($this->services->hasAccessPathView()) {
            try {
                $accessPaths = array_flip($this->services->authz()->load()->accessPathsOfRepository($repo->name));
            } catch (\Throwable) {
                $accessPaths = [];
            }
        }

        return $this->page('repositories/browse', [
            'repo' => $repo,
            'path' => $path,
            'entries' => $entries,
            'error' => $error,
            'accessPaths' => $accessPaths,
            'apacheLink' => $this->services->config->string('GUI', 'ApacheDirectoryListing'),
            'customLink' => $this->services->config->string('GUI', 'CustomDirectoryListing'),
            'canAddPath' => $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::ADD),
            'canRead' => $this->canReadFiles(),
        ], $repo->name, 'repositories');
    }

    public function file(string $parent, string $name): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::VIEW);
        $repo = $this->find($parent, $name);
        $path = RepositoryService::cleanRelative($this->request->query('path'));
        if ($path === '') {
            throw HttpException::notFound();
        }
        if (!$this->canReadFiles() || !$this->auth->canBrowse($repo->name, $path)) {
            throw HttpException::forbidden('Reading file contents requires the repository dump right.');
        }
        $limit = 262144;
        $content = $this->service()->cat($repo, $path, $limit + 1);
        $truncated = strlen($content) > $limit;
        $content = substr($content, 0, $limit);
        if ($this->request->query('raw') === '1') {
            return new Response(200, $content, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => Response::attachment(basename($path)),
            ]);
        }
        $binary = str_contains($content, "\0") || preg_match('//u', $content) !== 1;

        return $this->page('repositories/file', [
            'repo' => $repo,
            'path' => $path,
            'content' => $binary ? '' : $content,
            'binary' => $binary,
            'truncated' => $truncated,
        ], basename($path), 'repositories');
    }

    public function dump(string $parent, string $name): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::DUMP);
        if (!$this->canDump()) {
            throw HttpException::inactive('Repository dumps are disabled (GUI.RepositoryDumpEnabled).');
        }
        $repo = $this->find($parent, $name);
        $service = $this->service();
        $this->audit('repository.dump', $repo->name);
        $this->ctx->session->close(); // a long download must not block the user's other requests
        @set_time_limit(0);

        return Response::stream(static function () use ($service, $repo): void {
            $service->dump($repo, static function (string $chunk): void {
                echo $chunk;
                flush();
            });
        }, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => Response::attachment($repo->name . '-' . date('Ymd-His') . '.dump'),
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function load(string $parent, string $name): Response
    {
        $this->authorize(Acl::MOD_REPO, Acl::DUMP);
        if (!$this->canLoad()) {
            throw HttpException::inactive('Loading dump files is disabled (GUI.RepositoryLoadEnabled).');
        }
        $repo = $this->find($parent, $name);
        $file = $this->request->file('dumpfile');
        $target = '/repositories/' . $repo->parentId . '/' . rawurlencode($repo->name);
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->error('The upload failed. Check the file size limit (%0).', [human_bytes(self::maxUpload())]);

            return $this->redirect($target);
        }
        try {
            @set_time_limit(0);
            $this->service()->load($repo, $file['tmp_name']);
            $this->audit('repository.load', $repo->name, ['file' => $file['name'], 'bytes' => $file['size']]);
            $this->success('Loaded %0 into repository %1.', [$file['name'], $repo->name]);
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        return $this->redirect($target);
    }

    private function service(): RepositoryService
    {
        $service = $this->services->repositories();
        $this->requireActive($service !== null);

        return $service;
    }

    private function find(string $parent, string $name): Repository
    {
        try {
            $repo = $this->service()->find((int) $parent, $name);
        } catch (\InvalidArgumentException $e) {
            throw HttpException::notFound($e->getMessage());
        }
        if (!$this->auth->canSeeRepository($repo->name)) {
            throw HttpException::forbidden();
        }

        return $repo;
    }

    /** Creating a repository's root access path needs the same rights as creating it by hand. */
    private function canCreateAccessPath(string $path): bool
    {
        return $this->services->hasAccessPathEdit()
            && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::ADD)
            && $this->auth->canManagePath($path);
    }

    /** "Also remove access paths" needs the right to delete access paths. */
    private function canDeleteAccessPaths(): bool
    {
        return $this->services->hasAccessPathEdit() && $this->auth->can(Acl::MOD_ACCESSPATH, Acl::DELETE);
    }

    /** File contents are exported data: same right as downloading a dump. */
    private function canReadFiles(): bool
    {
        return $this->auth->can(Acl::MOD_REPO, Acl::DUMP);
    }

    private function canDelete(): bool
    {
        return $this->services->hasRepoEdit()
            && $this->services->config->bool('GUI', 'RepositoryDeleteEnabled')
            && $this->auth->can(Acl::MOD_REPO, Acl::DELETE);
    }

    private function canDump(): bool
    {
        return $this->services->hasRepoEdit()
            && $this->services->config->bool('GUI', 'RepositoryDumpEnabled')
            && $this->auth->can(Acl::MOD_REPO, Acl::DUMP);
    }

    private function canLoad(): bool
    {
        return $this->services->hasRepoEdit()
            && $this->services->config->bool('GUI', 'RepositoryLoadEnabled')
            && $this->auth->can(Acl::MOD_REPO, Acl::DUMP);
    }

    private static function maxUpload(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1073741824,
                'm' => $n * 1048576,
                'k' => $n * 1024,
                default => $n,
            };
        };

        return min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')));
    }
}
