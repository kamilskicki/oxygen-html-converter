$anchors = @(
    [pscustomobject]@{
        topic = "builder_url"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\admin\util.php"
        grep = "get_builder_loader_url|get_browse_mode_url"
        note = "Canonical ?oxygen=builder&id={postId} and browse mode URL generation"
    },
    [pscustomobject]@{
        topic = "admin_launcher"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\shared.php"
        grep = "builderLoaderUrl|ajaxNonce|openButton"
        note = "Admin-side launcher config passed into the editor screen"
    },
    [pscustomobject]@{
        topic = "admin_launcher_js"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\js\shared.js"
        grep = "getBuilderLoaderUrl|redirectToBuilder|getPostId|beforeunload.edit-post"
        note = "How wp-admin computes the builder redirect URL and clears unsaved-change blockers"
    },
    [pscustomobject]@{
        topic = "launcher_save_paths"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\js\shared.js"
        grep = "saveGutenberg|saveClassic|triggerSave|heartbeat-tick.autosave"
        note = "How the launcher waits for Gutenberg or Classic editor saves before or around builder launch"
    },
    [pscustomobject]@{
        topic = "classic_launcher"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\admin\launcher\classic.php"
        grep = "breakdance-launcher-button|breakdance-launcher-small-button|data-breakdance-action"
        note = "Classic editor launcher button classes for opening the builder"
    },
    [pscustomobject]@{
        topic = "frontend_admin_bar"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\admin\admin_bar_menu.php"
        grep = "breakdance_admin_bar_menu|edit_with_breakdance|edit_template_with_breakdance_|edit_header_with_breakdance_|edit_footer_with_breakdance_"
        note = "Frontend WordPress admin bar entrypoints into page, template, header, and footer builder sessions"
    },
    [pscustomobject]@{
        topic = "load_document"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\data\load.php"
        grep = "breakdance_load_document|load_document|builderMode|oxySelectors"
        note = "Builder boot payload and document load action"
    },
    [pscustomobject]@{
        topic = "save_document"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\data\save.php"
        grep = "breakdance_save|save_document|tree_json_string"
        note = "AJAX save handler and saved payload keys"
    },
    [pscustomobject]@{
        topic = "tree_storage"
        file = "D:\WordPress\Html to Oxygen\oxygen\plugin\data\tree.php"
        grep = "tree_json_string|get_tree"
        note = "Tree retrieval from post meta"
    },
    [pscustomobject]@{
        topic = "ui_labels"
        file = "D:\WordPress\Html to Oxygen\oxygen\languages\breakdance-builder.pot"
        grep = "Save and continue|Global Settings|Selectors|Design Presets|History"
        note = "Visible label anchors for Browser snapshots"
    },
    [pscustomobject]@{
        topic = "browse_iframe"
        file = "D:\WordPress\Html to Oxygen\oxygen\builder\dist\js\app.*.js"
        grep = "breakdance-browser-iframe|IframeWrapper|BrowseModeSave"
        note = "Built bundle clues for browse-mode iframe and save surface"
    },
    [pscustomobject]@{
        topic = "builder_bootstrap_error"
        file = "D:\WordPress\Html to Oxygen\oxygen\builder\dist\js\app.dec5a514.js.map"
        grep = "vp-wp|Entry assets/integration/oxygen/main.js not found"
        note = "Current browser-verified 500 overlay anchor from built source maps"
    }
)

$anchors | ConvertTo-Json -Depth 3
