<?php

namespace App\Support;

/**
 * Canonical workflow for every Order in ASH AI.
 *
 * Source of truth – the frontend constants file
 * (frontend/src/constants/formOptions/orderStages.js) MUST stay in sync
 * with this list. Any change here requires a matching frontend change.
 *
 * SEQUENCING MODEL (tier-based, supports one parallel fork-join)
 * --------------------------------------------------------------
 * Each stage carries an integer `seq` (its *tier* / dependency level).
 * The workflow is otherwise strictly sequential: a stage may start only
 * when EVERY stage in a LOWER tier is completed, and a tier is promoted
 * as a whole.
 *
 * The one exception (ASH AI Change Request 2026-06-02, Change 21) is the
 * SAMPLE phase, where `screen_making` and `material_prep_sample` run
 * concurrently — they share tier 6. `sample_cutting` (tier 7) is the JOIN:
 * it cannot start until BOTH tier-6 stages complete. Two (or more) stages
 * sharing a `seq` is the general way to express a parallel fork; the join
 * is simply the next tier. Everything else is one-stage-per-tier.
 *
 * Per-stage fields:
 *   key      – unique slug (matches `order_stages.stage` column)
 *   label    – human-readable name
 *   group    – grouping for UI (Pre-Production, Sample, Mass, Delivery)
 *   role     – default responsible role (used to assign + notify)
 *   seq      – tier / dependency level (NON-unique: parallel stages share it)
 *   sample   – is this a sample-production stage?
 *   mass     – is this a mass-production stage?
 *   gate     – is this a BLOCKING payment-verification gate? (Change 1/20)
 *   parallel – is this stage part of a concurrent (forked) tier? (Change 21)
 */
final class WorkflowStages
{
    public const STAGES = [
        // ---------- Pre-Production ----------
        // 🔒 Gate 1 — ₱1,000 sample fee. Finance/Superadmin/Admin only (Change 17).
        ['key' => 'payment_verification_sample',  'label' => 'Payment Verification (Sample)',   'group' => 'Pre-Production', 'role' => 'finance',           'seq' => 4,  'sample' => false, 'mass' => false, 'gate' => true,  'parallel' => false],

        // ---------- Sample Production ----------
        ['key' => 'graphic_artwork',              'label' => 'Graphic Artwork',                 'group' => 'Sample Production', 'role' => 'graphic_artist', 'seq' => 5,  'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => false],
        // ⑂ Parallel fork (tier 6): Screen Making ‖ Material Prep (sample).
        //   Both must complete before sample_cutting (tier 7) can start.
        ['key' => 'screen_making',                'label' => 'Screen Making',                   'group' => 'Sample Production', 'role' => 'screen_maker',   'seq' => 6,  'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => true],
        ['key' => 'material_prep_sample',         'label' => 'Material Prep (Sample)',          'group' => 'Sample Production', 'role' => 'material_prep',  'seq' => 6,  'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => true],
        // ⑃ Join + sample build (sequential): cutting → printing → sewing → packing.
        ['key' => 'sample_cutting',               'label' => 'Sample Cutting',                  'group' => 'Sample Production', 'role' => 'cutter',         'seq' => 7,  'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => false],
        ['key' => 'sample_printing',              'label' => 'Sample Printing',                 'group' => 'Sample Production', 'role' => 'printer',        'seq' => 8,  'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => false],
        ['key' => 'sample_sewing',                'label' => 'Sample Sewing',                   'group' => 'Sample Production', 'role' => 'sewer',          'seq' => 9,  'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => false],
        ['key' => 'sample_packing',               'label' => 'Sample Packing',                  'group' => 'Sample Production', 'role' => 'packer',         'seq' => 10, 'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => false],
        // Client checkpoint (kept) — must occur before the 60% DP gate.
        ['key' => 'sample_approval',              'label' => 'Sample Approval',                 'group' => 'Sample Production', 'role' => 'csr',            'seq' => 11, 'sample' => true,  'mass' => false, 'gate' => false, 'parallel' => false],

        // ---------- Mass Production ----------
        // 🔒 Gate 2 — 60% downpayment. Finance/Superadmin/Admin only (Change 17).
        ['key' => 'payment_verification_mass',    'label' => 'Payment Verification (Mass)',     'group' => 'Mass Production', 'role' => 'finance',          'seq' => 12, 'sample' => false, 'mass' => false, 'gate' => true,  'parallel' => false],
        // Source + prep the mass materials (the former "Purchase Materials").
        ['key' => 'material_prep_mass',           'label' => 'Material Prep',                   'group' => 'Mass Production', 'role' => 'material_prep',    'seq' => 13, 'sample' => false, 'mass' => false, 'gate' => false, 'parallel' => false],
        // Role-routed mass build (Change 19): cutting → printing → sewing → QA → packing.
        ['key' => 'mass_cutting',                 'label' => 'Mass Cutting',                    'group' => 'Mass Production', 'role' => 'cutter',           'seq' => 14, 'sample' => false, 'mass' => true,  'gate' => false, 'parallel' => false],
        ['key' => 'mass_printing',                'label' => 'Mass Printing',                   'group' => 'Mass Production', 'role' => 'printer',          'seq' => 15, 'sample' => false, 'mass' => true,  'gate' => false, 'parallel' => false],
        ['key' => 'mass_sewing',                  'label' => 'Mass Sewing',                     'group' => 'Mass Production', 'role' => 'sewer',            'seq' => 16, 'sample' => false, 'mass' => true,  'gate' => false, 'parallel' => false],
        ['key' => 'mass_qa',                      'label' => 'Trimming / QA',                   'group' => 'Mass Production', 'role' => 'quality_assurance','seq' => 17, 'sample' => false, 'mass' => true,  'gate' => false, 'parallel' => false],
        ['key' => 'mass_packing',                 'label' => 'Packing',                         'group' => 'Mass Production', 'role' => 'packer',           'seq' => 18, 'sample' => false, 'mass' => true,  'gate' => false, 'parallel' => false],

        // 🔒 Gate 3 — 40% balance. Finance/Superadmin/Admin only (Change 17/20).
        ['key' => 'payment_verification_balance', 'label' => 'Payment Verification (Balance)',  'group' => 'Mass Production', 'role' => 'finance',          'seq' => 19, 'sample' => false, 'mass' => false, 'gate' => true,  'parallel' => false],

        // ---------- Delivery + Closeout ----------
        ['key' => 'delivery',                     'label' => 'Delivery',                        'group' => 'Delivery', 'role' => 'logistics',              'seq' => 20, 'sample' => false, 'mass' => false, 'gate' => false, 'parallel' => false],
        ['key' => 'order_completed',              'label' => 'Order Completed',                 'group' => 'Delivery', 'role' => 'csr',                    'seq' => 21, 'sample' => false, 'mass' => false, 'gate' => false, 'parallel' => false],
        ['key' => 'client_notification',          'label' => 'Client Notification',             'group' => 'Delivery', 'role' => 'csr',                    'seq' => 22, 'sample' => false, 'mass' => false, 'gate' => false, 'parallel' => false],
    ];

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return self::STAGES;
    }

    /** Returns the stage slugs in execution order. @return array<int, string> */
    public static function keys(): array
    {
        return array_column(self::STAGES, 'key');
    }

    /** Looks up a stage definition by slug. Returns null when unknown. */
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
     * Returns the TIER (dependency level / `seq`) for a stage slug, or null.
     *
     * NOTE: tiers are NOT unique — parallel stages share one (e.g.
     * screen_making and material_prep_sample are both tier 6). This is the
     * value the workflow engine guards on ("all LOWER tiers complete").
     */
    public static function sequenceOf(string $key): ?int
    {
        $stage = self::find($key);
        return $stage['seq'] ?? null;
    }

    /** Alias that reads more clearly at engine call-sites. */
    public static function tierOf(string $key): ?int
    {
        return self::sequenceOf($key);
    }

    /** Highest tier number in the workflow. */
    public static function maxTier(): int
    {
        return max(array_column(self::STAGES, 'seq'));
    }

    /** The final stage's slug (used by reporting for "order finished"). */
    public static function lastKey(): string
    {
        $keys = self::keys();
        return $keys[count($keys) - 1];
    }

    /** Is this stage a blocking payment-verification gate? (Change 1/20) */
    public static function isPaymentGate(string $key): bool
    {
        return (bool) (self::find($key)['gate'] ?? false);
    }

    /** Slugs of all blocking payment gates, in order. @return array<int,string> */
    public static function paymentGateKeys(): array
    {
        return array_values(array_map(
            static fn ($s) => $s['key'],
            array_filter(self::STAGES, static fn ($s) => ! empty($s['gate'])),
        ));
    }

    /**
     * Slugs of all sample-production stages (sample === true), in order:
     * graphic_artwork → screen_making ‖ material_prep_sample → sample_cutting
     * → sample_printing → sample_sewing → sample_packing → sample_approval.
     *
     * This is exactly the set a sample-approval REJECT loops back over (see
     * OrderStagesService::resetSampleSubflow). The sample PAYMENT gate
     * (payment_verification_sample) is a gate, NOT a sample-production stage
     * (sample === false), so it is deliberately excluded — a reject never
     * re-charges the sample fee.
     *
     * @return array<int,string>
     */
    public static function sampleStageKeys(): array
    {
        return array_values(array_map(
            static fn ($s) => $s['key'],
            array_filter(self::STAGES, static fn ($s) => ! empty($s['sample'])),
        ));
    }

    /** All stage slugs sharing the given tier. @return array<int,string> */
    public static function stagesAtTier(int $tier): array
    {
        return array_values(array_map(
            static fn ($s) => $s['key'],
            array_filter(self::STAGES, static fn ($s) => $s['seq'] === $tier),
        ));
    }

    /** Is the given tier a parallel (forked) tier — i.e. more than one stage? */
    public static function isParallelTier(int $tier): bool
    {
        return count(self::stagesAtTier($tier)) > 1;
    }

    /**
     * THE FORK-JOIN BRAIN (pure, framework-free — unit-testable in isolation).
     *
     * Given the current status of every canonical stage for an order, returns
     * the slugs that should transition pending → in_progress *right now*.
     *
     * Rule: find the lowest tier that still has a PENDING stage; promote every
     * pending stage at that tier — but ONLY if every stage in a strictly lower
     * tier is already completed. That single rule yields:
     *   - linear advance for one-stage tiers, and
     *   - fork (promote both tier-6 stages at once) + join (tier 7 waits until
     *     BOTH tier-6 stages are completed) for the parallel branch.
     *
     * @param array<string,string> $statusBySlug  slug => OrderStage status
     * @return array<int,string>                   slugs to start now (may be 0..n)
     */
    public static function nextActivations(array $statusBySlug): array
    {
        $completed = 'completed';

        // Consider ONLY the stages this order actually has. In production an
        // order always carries the full canonical set (initializeForOrder
        // creates all of them), so this is a no-op there — but callers and
        // tests may pass a partial set, and we mirror the original engine,
        // which only ever reasoned over existing order_stages rows.
        $present = static fn (array $s): bool => array_key_exists($s['key'], $statusBySlug);

        // Lowest tier that still has a pending stage.
        $minPendingTier = null;
        foreach (self::STAGES as $stage) {
            if (! $present($stage)) {
                continue;
            }
            if ($statusBySlug[$stage['key']] === 'pending') {
                if ($minPendingTier === null || $stage['seq'] < $minPendingTier) {
                    $minPendingTier = $stage['seq'];
                }
            }
        }

        if ($minPendingTier === null) {
            return []; // nothing pending — workflow finished or fully active
        }

        // Join guard: every PRESENT stage in a strictly lower tier must be
        // completed (this is what makes the fork's join wait for both branches).
        foreach (self::STAGES as $stage) {
            if (! $present($stage)) {
                continue;
            }
            if ($stage['seq'] < $minPendingTier
                && $statusBySlug[$stage['key']] !== $completed) {
                return []; // a lower tier (e.g. the other fork branch) is unfinished
            }
        }

        // Clear to promote — return all present pending stages at the tier.
        $promote = [];
        foreach (self::STAGES as $stage) {
            if (! $present($stage)) {
                continue;
            }
            if ($stage['seq'] === $minPendingTier
                && $statusBySlug[$stage['key']] === 'pending') {
                $promote[] = $stage['key'];
            }
        }
        return $promote;
    }

    /**
     * Phase classification used by reporting to group cycle-time:
     *   'sample' | 'mass' | 'preprod' | 'delivery' | null (unknown slug)
     *
     * Payment-verification gates and material_prep_mass are administrative
     * gates, NOT production-cycle work, so they classify as 'preprod' and stay
     * out of mass/sample cycle metrics — matching the old behaviour for the
     * former payment_verification_mass / purchase_materials checkpoints.
     */
    public static function phaseFor(string $key): ?string
    {
        $stage = self::find($key);
        if (! $stage) {
            return null;
        }

        if (! empty($stage['sample'])) return 'sample';
        if (! empty($stage['mass']))   return 'mass';

        if (in_array($key, [
            'payment_verification_sample',
            'payment_verification_mass',
            'payment_verification_balance',
            'material_prep_mass',
        ], true)) {
            return 'preprod';
        }

        $group = $stage['group'] ?? '';
        if ($group === 'Pre-Production') return 'preprod';
        if ($group === 'Delivery')       return 'delivery';

        return null;
    }

    /**
     * The stage that comes immediately after the given one in list order,
     * or null when it's the last. With the parallel tier this is list-order
     * (array) successor, used only for labels/notifications, never for gating
     * (gating uses nextActivations()).
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

    /**
     * Map a portal role slug to the stage keys it works on.
     * Used by PortalAssignmentService to filter "what's assigned to me?".
     *
     * @return array<int,string>
     */
    public static function stagesForPortalRole(string $portalRole): array
    {
        return match ($portalRole) {
            'csr' => [
                'sample_approval', 'order_completed', 'client_notification',
            ],
            'finance' => [
                'payment_verification_sample',
                'payment_verification_mass',
                'payment_verification_balance',
            ],
            'graphic_artist'  => ['graphic_artwork'],
            'screen_maker'    => ['screen_making'],
            // Material Prep now owns the sample-phase sourcing fork AND the
            // mass-phase sourcing stage (the former "Purchase Materials").
            'material_prep', 'purchasing', 'warehouse_manager' => [
                'material_prep_sample', 'material_prep_mass',
            ],
            // Mass production is now role-routed (Change 19) into discrete
            // cutting/printing/sewing stages, mirroring the sample phase.
            'cutter'  => ['sample_cutting', 'mass_cutting'],
            'printer' => ['sample_printing', 'mass_printing'],
            'sewer'   => ['sample_sewing', 'mass_sewing'],
            'quality_assurance', 'qa' => ['mass_qa'],
            'packer'  => ['sample_packing', 'mass_packing'],
            // Unified QA/Packer portal serves QA + both packing stages.
            'qa_packer' => ['mass_qa', 'sample_packing', 'mass_packing'],
            'logistics' => ['delivery'],
            default => [],
        };
    }
}
