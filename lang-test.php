<?php
$try = function ($lang, $label) {
    $o = Selfcap_Plugin::options(); $o['plugin_language'] = $lang; update_option('selfcap_options', $o);
    Selfcap_Plugin::load_translations();
    echo str_pad($label, 44), __('Overview', 'selfcap'), "\n";
};
$pack = WP_LANG_DIR.'/plugins/selfcap-pt_BR.mo';
@unlink($pack);
$try('pt', 'sem arquivo nenhum, PT forçado:');
$try('auto', 'sem arquivo nenhum, automático (en_US):');
wp_mkdir_p(dirname($pack));
copy($args[0], $pack);
$try('pt', 'com pacote do translate.wordpress.org, PT:');
switch_to_locale('pt_BR');
$try('auto', 'com pacote, automático (site em pt_BR):');
restore_previous_locale();
unlink($pack);
$try('auto', 'sem pacote, automático (en_US):');
