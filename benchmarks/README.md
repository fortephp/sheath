# Benchmarks

Run the deterministic microbenchmarks and end-to-end linter benchmark from the project root:

```bash
composer benchmark
```

Use `composer benchmark:baseline`, `composer benchmark:cache`,
`composer benchmark:fixer`, `composer benchmark:js-scanner`, or
`composer benchmark:suppressions` to run one microbenchmark. Use
`composer benchmark:aria-hidden` to exercise nested focusability indexing. Use
`composer benchmark:linter` to measure full parsing plus the recommended preset.
Use `composer benchmark:rule-scheduling` to compare per-file rule preparation
and repeated versus file-shared package analysis.
Each result is the
median of five samples and excludes fixture setup. The scripts report several
input sizes so changes in scaling are visible; they do not enforce
machine-dependent timing thresholds in CI.
