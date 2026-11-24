"""Summarize alternating paired runs; fail on missing cases or SEO changes."""
import json
import statistics
import sys

prefix = "WD_COMBINED_SEO "
with open(sys.argv[1], encoding="utf-8") as stream:
	rows = [json.loads(line[len(prefix):]) for line in stream if line.startswith(prefix)]

cases = [
	"ordinary-empty", "ordinary-persisted", "ordinary-text", "ordinary-local-file",
	"ordinary-separate-x", "native-debate", "native-argument", "native-hreflang", "parser",
]
assert len(rows) == 36, f"Expected 36 independent processes, got {len(rows)}"
print("### Combined WikiSEO benchmark (isolated real MediaWiki SQLite lab)")
print("| Scenario | CPU before / after (µs) | CPU change | Wall before / after (µs) | Peak increment before / after (KiB per batch) | First SQL before / after | Warm SQL per operation before / after |")
print("|---|---:|---:|---:|---:|---:|---:|")
for case in cases:
	paired = {version: [r for r in rows if r["case"] == case and r["version"] == version]
		for version in ("baseline", "optimized")}
	for version, group in paired.items():
		assert len(group) == 2 and {r["round"] for r in group} == {1, 2}
	hashes = {r["seo_sha256"] for group in paired.values() for r in group}
	assert len(hashes) == 1, f"SEO output changed for {case}: {hashes}"
	def stats(group):
		median = statistics.median
		return {
			"cpu_us": median([v for r in group for v in r["cpu_us_samples"]]),
			"wall_us": median([v for r in group for v in r["wall_us_samples"]]),
			"peak_kib": median([v for r in group for v in r["peak_increment_bytes_samples"]]) / 1024,
			"first_sql": median([r["first_sql"]["total"] for r in group]),
			"warm_sql_per_op": median([r["warm_sql"]["total"] / r["warm_sql_iterations"] for r in group]),
			"warm_page_props_per_op": median([r["warm_sql"]["page_props"] / r["warm_sql_iterations"] for r in group]),
			"warm_file_sql_per_op": median([r["warm_sql"]["file_catalog"] / r["warm_sql_iterations"] for r in group]),
		}
	before, after = (stats(paired[v]) for v in ("baseline", "optimized"))
	change = (after["cpu_us"] / before["cpu_us"] - 1) * 100
	print(f"| {case} | {before['cpu_us']:.2f} / {after['cpu_us']:.2f} | {change:+.1f}% | "
		f"{before['wall_us']:.2f} / {after['wall_us']:.2f} | "
		f"{before['peak_kib']:.1f} / {after['peak_kib']:.1f} | "
		f"{before['first_sql']:.1f} / {after['first_sql']:.1f} | "
		f"{before['warm_sql_per_op']:.2f} / {after['warm_sql_per_op']:.2f} |")
	print("WD_COMBINED_SUMMARY " + json.dumps({
		"case": case, "baseline": before, "optimized": after,
		"cpu_change_percent": change, "seo_sha256": next(iter(hashes)),
	}, separators=(",", ":")))
print("All paired SEO output hashes match. CPU/wall times are per operation; memory is incremental PHP allocator peak over an equal-size batch. SQL counts come from observed debug query records on the two lab connections, not service-call proxies. No production latency or production traffic weighting is inferred.")
