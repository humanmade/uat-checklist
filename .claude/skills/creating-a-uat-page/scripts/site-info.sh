#!/usr/bin/env bash
# Prints what the skill needs to know before writing a spec: the site's language, its theme, whether the
# checklist plugin is active, and whether it has a hub page. Writes nothing.
set -euo pipefail

# shellcheck source=config.sh
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

# Passed in rather than read with getenv(), which runs inside the host's container on most local environments.
hub_slug="${UAT_HUB_SLUG-hub}"
if [[ ! "$hub_slug" =~ ^[a-z0-9-]*$ ]]; then
	echo "UAT_HUB_SLUG must be a page slug: lowercase letters, digits and hyphens." >&2
	exit 1
fi

wp eval '
$hub_slug = "'"$hub_slug"'";
$hub      = $hub_slug === "" ? null : get_page_by_path( $hub_slug );
$theme    = wp_get_theme();
$locale   = get_locale();
require_once ABSPATH . "wp-admin/includes/translation-install.php";
$languages = wp_get_available_translations();

printf( "Site:            %s\n", home_url( "/" ) );
printf(
	"Locale:          %s (%s)\n",
	$locale,
	$locale === "en_US"
		? "English (United States)"
		: ( $languages[ $locale ]["english_name"] ?? "not a WordPress.org locale" )
);
printf( "Spec language:   \"%s\"\n", strtolower( strtok( $locale, "_" ) ) );
printf( "Admin languages: %s\n", implode( ", ", array_merge( [ "en_US" ], get_available_languages() ) ) );
printf(
	"Theme:           %s (%s)%s\n",
	$theme->get( "Name" ),
	$theme->get_stylesheet(),
	wp_is_block_theme() ? ", block theme" : ", classic theme"
);
printf(
	"Plugin:          uat-checklist %s\n",
	is_plugin_active( "uat-checklist/uat-checklist.php" ) ? "active" : "NOT active"
);
printf(
	"Hub page:        %s\n",
	$hub && $hub->post_status === "publish"
		? get_permalink( $hub )
		: "none, so pages build at the top level"
);
'
