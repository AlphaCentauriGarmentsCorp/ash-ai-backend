<?php

namespace App\Support;

/**
 * Canonical sequential workflow for every Order in ASH AI.
 *
 * Source of truth – the frontend constants file
 * (frontend/src/constants/formOptions/orderStages.js) MUST stay in sync
 * with this list. Any change here requires a matching frontend change.
 *
 * The order is fixed: stages must be completed in this exact sequence
 * (see ASH AI master brief §5).
 */
final class WorkflowStages
{
    /**
     * Master list of every stage in the workflow, in execution order.
     *
     * Each entry:
     *   key      – unique slug (matches `order_stages.stage` column)
     *   label    – human-readable name
     *   group    – grouping for UI (Pre-Production, Sample, Mass, Delivery)
     *   role     – default responsible role (used to assign + notify)
     *   sample   – is this a "sample creation" stage?
     *   mass     – is this a "mass production" stage?
     *
     * Stages 1..14 align with the 14-step "Production Workflow" diagram
     * in ASH AI master brief §5 / image 8.
     */
    public const STAGES = [
        // ---------- Pre-Production ----------
        [
            'key'    => 'inquiry',
            'label'  => 'Inquiry',
            'group'  => 'Pre-Production',
            'role'   => 'csr',
            'sample' => false,
            'mass'   => false,
        ],
        [
            'key'    => 'quotation',
            'label'  => 'Quotation',
            'group'  => 'Pre-Production',
            'role'   => 'csr',
            'sample' => false,
            'mass'   => false,
        ],
        [
            'key'    => 'quotation_approval',
            'label'  => 'Quotation Approval',
            'group'  => 'Pre-Production',
            'role'   => 'csr',
            'sample' => false,
            'mass'   => false,
        ],
        [
            'key'    => 'payment_verification_sample',
            'label'  => 'Payment Verification (Sample)',
            'group'  => 'Pre-Production',
            'role'   => 'finance',
            'sample' => false,
            'mass'   => false,
        ],

        // ---------- Sample Production ----------
        [
            'key'    => 'graphic_artwork',
            'label'  => 'Graphic Artwork',
            'group'  => 'Sample Production',
            'role'   => 'graphic_artist',
            'sample' => true,
            'mass'   => false,
        ],
        [
            'key'    => 'screen_making',
            'label'  => 'Screen Making',
            'group'  => 'Sample Production',
            'role'   => 'screen_maker',
            'sample' => true,
            'mass'   => false,
        ],
        [
            'key'    => 'sample_creation',
            'label'  => 'Sample Creation',
            'group'  => 'Sample Production',
            'role'   => 'sample_maker',
            'sample' => true,
            'mass'   => false,
        ],
        [
            'key'    => 'sample_approval',
            'label'  => 'Sample Approval',
            'group'  => 'Sample Production',
            'role'   => 'csr',
            'sample' => true,
            'mass'   => false,
        ],

        // ---------- Mass Production ----------
        [
            'key'    => 'mass_production',
            'label'  => 'Mass Production',
            'group'  => 'Mass Production',
            'role'   => 'general_manager',
            'sample' => false,
            'mass'   => true,
        ],
        [
            'key'    => 'quality_control',
            'label'  => 'Quality Control',
            'group'  => 'Mass Production',
            'role'   => 'quality_assurance',
            'sample' => false,
            'mass'   => true,
        ],
        [
            'key'    => 'packing',
            'label'  => 'Packing',
            'group'  => 'Mass Production',
            'role'   => 'packer',
            'sample' => false,
            'mass'   => true,
        ],

        // ---------- Delivery + Closeout ----------
        [
            'key'    => 'delivery',
            'label'  => 'Delivery',
            'group'  => 'Delivery',
            'role'   => 'logistics',
            'sample' => false,
            'mass'   => false,
        ],
        [
            'key'    => 'order_completed',
            'label'  => 'Order Completed',
            'group'  => 'Delivery',
            'role'   => 'csr',
            'sample' => false,
            'mass'   => false,
        ],
        [
            'key'    => 'client_notification',
            'label'  => 'Client Notification',
            'group'  => 'Delivery',
            'role'   => 'csr',
            'sample' => false,
            'mass'   => false,
        ],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::STAGES;
    }

    /**
     * Returns just the stage slugs in order (1..N).
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_column(self::STAGES, 'key');
    }

    /**
     * Looks up a stage definition by its slug. Returns null when unknown.
     */
    public static function find(string $key): ?array
    {
        foreach (self::STAGES as $stage) {
            if ($stage['key'] === $key) {
                return $stage;
            }
        }
        return null;
    }

    /**
     * Returns the (1-based) sequence number for a stage slug, or null.
     */
    public static function sequenceOf(string $key): ?int
    {
        foreach (self::STAGES as $idx => $stage) {
            if ($stage['key'] === $key) {
                return $idx + 1;
            }
        }
        return null;
    }

    /**
     * Returns the phase classification for a stage slug, used by Phase 4
     * reporting to group cycle-time and production counts:
     *   - 'sample'      = sample-production stages only
     *   - 'mass'        = mass-production stages only
     *   - 'preprod'     = inquiry / quotation / payment-verification stages
     *   - 'delivery'    = delivery / closeout stages
     *   - null when the slug is unknown
     *
     * Sample/Mass reports filter by this — pre-production and delivery
     * stages are correctly excluded from production-cycle metrics.
     */
    public static function phaseFor(string $key): ?string
    {
        $stage = self::find($key);
        if (! $stage) {
            return null;
        }

        if (! empty($stage['sample'])) return 'sample';
        if (! empty($stage['mass']))   return 'mass';

        $group = $stage['group'] ?? '';
        if ($group === 'Pre-Production') return 'preprod';
        if ($group === 'Delivery')       return 'delivery';

        return null;
    }

    /**
     * Returns the stage that comes after the given one, or null when the
     * given stage is the final one in the workflow.
     */
    public static function nextAfter(string $key): ?array
    {
        $found = false;
        foreach (self::STAGES as $stage) {
            if ($found) {
                return $stage;
            }
            if ($stage['key'] === $key) {
                $found = true;
            }
        }
        return null;
    }
}
