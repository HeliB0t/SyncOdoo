# Changelog

## 0.3.0 - 2026-04-14

### Added

- Export of Dolibarr invoice documents (PDF, images) to Odoo during synchronization via `ir.attachment`.
- New admin option "Exporter les documents Dolibarr vers Odoo" (`SYNCODOO_EXPORT_INVOICE_FILE`).
- Duplicate detection by filename to avoid re-uploading existing attachments to Odoo.
- Attachment export integrated in both invoice creation and invoice update (Dolibarr → Odoo).

### Fixed

- `ensureVatRateTableExists()`: the `ALTER TABLE ADD COLUMN correct_rate` no longer produces a `Duplicate column name` SQL error that polluted subsequent error messages.

### Changed

- Module version bumped from 0.2.0 to 0.3.0.

## 0.2.0 - 2026-03-31

### Added

- Optional bank transaction synchronization between one Odoo bank journal and one Dolibarr bank account.
- Bank sync settings in the admin page: enable flag, journal selector, account selector, direction, and start date.
- Internal mapping table for synchronized bank lines to avoid duplicates and track updates.
- Optional import of Odoo invoice attachments into Dolibarr invoices.
- CLI data simulation tool in `tools/simulate_data.php` for generating partners and invoices on both sides.
- Dashboard summary block for the latest synchronization statistics when values are non-zero.

### Changed

- Module version bumped from 0.1.0 to 0.2.0.
- Module labels now consistently display `SyncOdoo` without an extra space.
- README updated to document bank sync, simulation tooling, and current release details.

### Fixed

- Bank sync compatibility with Odoo versions that do not expose `move_name` on `account.bank.statement.line`.
- Bank export compatibility with Odoo versions that reject string values in `transaction_details`.
- French language file cleaned up after a corrupted concatenated block.

## 0.1.0

### Initial release

- Bidirectional synchronization for third parties and invoices.
- Divergence detection and manual resolution workflow.
- Odoo connection diagnostics and logging.
- VAT rate verification workflow.