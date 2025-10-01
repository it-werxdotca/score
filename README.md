# Score Module for Drupal

The Score module provides a dynamic, configurable scoring system for Drupal content types. It allows site builders to define flexible scoring rules, automatically calculates scores based on field values, and stores the results as entity fields. The module is extensible and can be adapted to advanced use cases, including integration with Paragraphs.

---

## Features

- Define multiple scoring systems per content type (bundle).
- Score calculation based on configurable components (e.g., percentages, field mappings, taxonomy, boolean flags).
- User interface for managing score systems and configuration at `/admin/config/score`.
- Dynamic field detection and creation for storing scores.
- Extensible design for custom scoring logic.
- Logging and error handling for missing fields or configuration issues.

---

## Installation

1. Place the `score` module directory in your Drupal site's `/modules/custom/` directory.
2. Enable the module via Drupal's Extend page (`/admin/modules`) or use Drush:
   ```bash
   drush en score
   ```
3. The module requires the `field` and `node` modules (enabled by default in most Drupal sites).

---

## Configuration & Usage

1. **Access the Score Admin UI**
   Go to **Configuration > Score Systems** (`/admin/config/score`).

2. **Add a Score System**
   - Click "Add Score System".
   - Provide a unique machine name for the scoring system.
   - Select the content type (bundle) to which this scoring system will apply.

3. **Configure Scoring Components**
   - Choose or enter the field names and settings for each scoring component.
   - Supported component types include:
     - `percentage_calculation`: Calculates a percentage from two fields.
     - `direct_percentage`: Uses a single field as a percentage.
     - `list_mapping`: Maps field values to points.
     - `capped_linear`: Linear scale with a cap.
     - `taxonomy_field_value`: Pulls values from a referenced taxonomy term.
     - `boolean_points`: Assigns points for boolean fields.

4. **Field Management**
   - When saving a new score system, the module will automatically create a field on the target content type if it does not exist.
   - **Note:** By default, automatic field creation is only supported for node/entity fields, not for Paragraphs.

5. **Recalculation**
   - Use the "Recalculate" operation in the Score Systems admin interface to reprocess all existing entities for a score system.

---

## Field Requirements

- Fields referenced in scoring components must exist on the content type before scoring.
- The module can auto-create score fields on content types but **does not** auto-create fields on Paragraph types.
- For advanced field types (e.g., multi-value, entity reference), ensure the scoring logic matches your data structure.

---

## Paragraphs & Advanced Usage

- **Paragraphs Support:**
  Out-of-the-box, the module does not automatically score fields inside Paragraphs entities.
  To use scoring with Paragraphs:
  - You must manually create any scoring fields on the relevant Paragraph types.
  - You may need to extend the module's field value extraction logic to aggregate or sum values from Paragraphs attached to a node.
  - See `ScoreCalculatorService` for examples on customizing field traversal.

- **Custom Logic:**
  Developers can extend or override the scoring logic by implementing custom services or plugins.

---

## Troubleshooting

- If you see errors about missing fields, ensure all referenced fields exist and are correctly named.
- For log-related errors (`Html::escape(): Argument #1 ($text) must be of type string, null given`), always ensure log messages and placeholders are strings.
- Check logs at `/admin/reports/dblog` for warnings related to scoring.

---

## Uninstall

1. Remove all score systems via the Score admin UI.
2. Uninstall the module via the Drupal UI or Drush:
   ```bash
   drush pm-uninstall score
   ```

---

## Development & Contributing

The Score module is open for contributions. To propose improvements, file issues, or submit pull requests, visit the [GitHub repository](https://github.com/itwerxdotca/score).

---

## License

This module is custom and provided as-is. See the repository for details.
