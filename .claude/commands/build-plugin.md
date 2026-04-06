Bump the plugin version in `print-api/print-api.php` (patch increment, e.g. 1.0.2 → 1.0.3), then build the distributable zip.

Steps:
1. Read `print-api/print-api.php` and find the current version — it appears in two places:
   - The plugin header comment: `* Version:      X.Y.Z`
   - The constant definition: `define( 'PRINT_API_VERSION', 'X.Y.Z' );`
2. Increment the patch segment (the last number) by 1.
3. Write both occurrences back with the new version string.
4. Run `bash buildZip.sh` from the repo root and confirm it succeeds.
5. Report the old version, the new version, and the output path of the zip.
