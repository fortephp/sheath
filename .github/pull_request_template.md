<!--
Thanks for sending this. A short description of what changes and why is
usually enough; the checklist below is what CI will look at anyway.
-->

## What this changes

<!-- One or two sentences. If it fixes an issue, link it. -->

## Checklist

- [ ] `vendor/bin/pest` passes
- [ ] `vendor/bin/phpstan analyse` passes
- [ ] `vendor/bin/pint` and `vendor/bin/rector process --dry-run` are clean

### If you added or changed a rule

- [ ] Focused tests cover valid input, invalid input, options, and fixes where applicable
- [ ] Its page at `fortephp.com/docs/sheath/v1/{rule-id}` states the real category, severity, fixability, and options
- [ ] It belongs to a preset in `RulePreset` (the suite fails if a rule is left unclassified)
- [ ] Any documented option lists the default used by the implementation

### If it emits a fix

- [ ] `AutofixOutputTest` has a case pinning the exact output
- [ ] The fix is marked dangerous if it can change what the page renders
