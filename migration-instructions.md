# Aspen to Kitmage data migration

The plugin stores its configuration in two rows in the WordPress options table. Run the commands below **after backing up the database** and deactivating the Aspen version of the plugin.

If the WordPress database uses a table prefix other than `wp_`, replace `wp_options` in every command with the actual options table name before running the migration.

```sql
START TRANSACTION;

UPDATE wp_options
SET option_name = 'kitmage_addon_manager_rules'
WHERE option_name = 'aspen_addon_manager_rules';

UPDATE wp_options
SET option_name = 'kitmage_addon_manager_debug'
WHERE option_name = 'aspen_addon_manager_debug';

COMMIT;
```

Confirm that the Kitmage option rows exist and the Aspen rows no longer remain:

```sql
SELECT option_name, option_value
FROM wp_options
WHERE option_name IN (
    'aspen_addon_manager_rules',
    'aspen_addon_manager_debug',
    'kitmage_addon_manager_rules',
    'kitmage_addon_manager_debug'
);
```

Both `kitmage_addon_manager_rules` and `kitmage_addon_manager_debug` should be returned. No option whose name begins with `aspen_addon_manager_` should remain. You can then activate Kitmage Add-on Manager.

If either `UPDATE` reports a duplicate-key error, a Kitmage option row already exists. Roll back instead of committing, compare the old and new row values, retain the desired value, and then remove the obsolete Aspen row manually.
