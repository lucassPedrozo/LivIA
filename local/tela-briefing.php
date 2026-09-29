<?php
/**
 * Uma página de briefing de mentira, só para o widget ter onde aparecer.
 *
 * O formulário é decoração: o que interessa é o widget por cima dele, com o
 * CSS e o JS de produção, carregados pelo shortcode como no WordPress.
 */
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Briefing — Site em 72h</title>
<link rel="stylesheet" href="/local/tela-briefing.css">
<?php wp_head(); ?>
</head>
<body>
<header class="topo">
	<div class="marca"><span class="marca-ponto"></span>Estúdio Exemplo</div>
	<nav>Briefings</nav>
</header>

<main class="folha">
	<p class="etapa">Etapa 1 de 3</p>
	<h1>Briefing — Site em 72h</h1>
	<p class="intro">Conte um pouco sobre o seu negócio. Quanto mais completo, mais rápido o seu site fica pronto.</p>

	<form onsubmit="return false">
		<label>Nome da empresa
			<input type="text" value="Padaria Aurora">
		</label>
		<label>Responsável pelo projeto
			<input type="text" value="Marina Duarte">
		</label>
		<div class="duas">
			<label>Telefone
				<input type="text" placeholder="(00) 00000-0000">
			</label>
			<label>E-mail
				<input type="text" placeholder="voce@empresa.com.br">
			</label>
		</div>
		<label>Domínio do site
			<input type="text" placeholder="ex.: suaempresa.com.br">
			<small>Se ainda não tiver, escreva o nome que gostaria e a palavra "sugestão".</small>
		</label>
		<label>Sobre o negócio
			<textarea rows="4" placeholder="O que vocês fazem, para quem, e o que torna vocês diferentes."></textarea>
		</label>
		<button type="button" class="avancar">Continuar</button>
	</form>
</main>

<?php echo $ancora; // phpcs:ignore ?>
<?php wp_footer(); ?>
</body>
</html>
