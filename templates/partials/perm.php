<?php /** @var SvnManage\Authz\Permission $perm */
$cls = match ($perm) {
    SvnManage\Authz\Permission::ReadWrite => 'badge-rw',
    SvnManage\Authz\Permission::Read => 'badge-r',
    default => 'badge-none',
};
$ic = match ($perm) {
    SvnManage\Authz\Permission::ReadWrite => 'pencil',
    SvnManage\Authz\Permission::Read => 'eye',
    default => 'circle-slash',
};
?>
<span class="badge <?= $cls ?>"><?= icon($ic, 'size-3') ?><?= t($perm->label()) ?></span>
