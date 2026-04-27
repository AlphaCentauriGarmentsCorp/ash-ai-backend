<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RbacSeeder extends Seeder
{
    /**
     * Seed base RBAC roles.
     */
    public function run(): void
    {
        $permissions = [
            'access.clients',
            'access.orders',
            'access.quotations',
            'access.dropdown-settings',
            'access.quotation-settings',
            'access.download',
            'access.employees',
            'access.rbac',
            'access.equipment',
            'access.suppliers',
            'access.materials',
            'access.screens',
            'access.payment-methods',
            'access.shipping-methods',
            'access.courier-list',
            'access.sewing-subcontractor',
            'access.order-stages',
            'access.graphic-design',
            'access.screen-making',
            'access.screen-checking',
            'access.screen-maintenance',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $csr = Role::firstOrCreate(['name' => 'csr', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $superadmin->syncPermissions($permissions);
        $admin->syncPermissions($permissions);
        $csr->syncPermissions([
            'access.clients',
            'access.orders',
            'access.quotations',
            'access.dropdown-settings',
            'access.quotation-settings',
        ]);
    }
}
