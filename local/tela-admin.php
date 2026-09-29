<?php
/**
 * Moldura do wp-admin, só o suficiente para o painel da LivIA parecer em casa.
 *
 * O conteúdo ($conteudo) é o render() de produção. O CSS do plugin entra como
 * no WordPress; o local/wp-admin.css imita as classes do núcleo que ele usa
 * (.wrap, .button, .nav-tab, .widefat, .notice).
 */
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LivIA ‹ Estúdio Exemplo — WordPress</title>
<link rel="stylesheet" href="/local/wp-admin.css">
<link rel="stylesheet" href="/livia/admin/livia-admin.css?ver=<?php echo esc_attr( LIVIA_VERSAO ); ?>">
</head>
<body class="wp-admin">
<div id="wpadminbar"><span class="wp-logo">W</span> Estúdio Exemplo</div>
<div id="adminmenu">
	<a>Painel</a>
	<a>Posts</a>
	<a>Páginas</a>
	<a>Plugins</a>
	<a class="atual">Configurações</a>
	<div class="submenu">
		<a>Geral</a>
		<a>Leitura</a>
		<a class="atual">LivIA</a>
	</div>
</div>
<div id="wpcontent">
<?php echo $conteudo; // phpcs:ignore ?>
</div>
</body>
</html>
