<?php
/**
 * Casos da trava anti-invenção.
 *
 * Cada caso é dado, não código: nome, texto, se deve bloquear e (opcional) um
 * trecho que o motivo do bloqueio precisa conter. Portar isto para PHPUnit
 * depois é envolver o array num data provider — nenhum caso precisa mudar.
 *
 * Recebe a base já carregada porque alguns casos são construídos a partir dela
 * (o despejo de prompt, por exemplo, é um pedaço literal da própria base).
 */

return function ( $base ) {

	// Um despejo de prompt: um pedaço literal e longo da instrução da base.
	$instrucao = array();
	foreach ( preg_split( '/\R/', $base ) as $linha ) {
		$limpa = trim( $linha );
		if ( '' !== $limpa && 0 !== strpos( $limpa, '>' ) && 0 !== strpos( $limpa, '#' ) ) {
			$instrucao[] = $limpa;
		}
	}
	$despejo = implode( ' ', array_slice( $instrucao, 8, 14 ) );

	return array(

		// ---------------------------------------------- paridade com o livia.py
		// Comportamento que já existia e não pode regredir no porte.

		array(
			'grupo'    => 'paridade',
			'nome'     => 'telefone real da Joinvix passa',
			'texto'    => 'Fale com a nossa equipe: WhatsApp (47) 3433-5066.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'paridade',
			'nome'     => 'exemplo didático da base passa',
			'texto'    => 'Escreva assim: (47) 99999-9999.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'paridade',
			'nome'     => 'e-mail de exemplo da base passa',
			'texto'    => 'Por exemplo: contato@suaempresa.com.br',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'paridade',
			'nome'     => 'telefone inventado bloqueia',
			'texto'    => 'Pode ligar para (11) 98765-4321 que resolvem.',
			'bloqueia' => true,
			'motivo'   => 'telefone',
		),
		array(
			'grupo'    => 'paridade',
			'nome'     => 'e-mail inventado bloqueia',
			'texto'    => 'Manda um e-mail para suporte@joinvix.com.br.',
			'bloqueia' => true,
			'motivo'   => 'e-mail',
		),
		array(
			'grupo'    => 'paridade',
			'nome'     => 'R$ bloqueia (a base não tem preço nenhum)',
			'texto'    => 'O site sai por R$ 1.500.',
			'bloqueia' => true,
			'motivo'   => 'dinheiro',
		),

		// ---------------------------------------------- os furos tapados na Fase 2

		array(
			'grupo'    => 'furo',
			'nome'     => 'URL inventada bloqueia',
			'texto'    => 'É só acessar joinvix.com.br/painel-do-cliente e reenviar.',
			'bloqueia' => true,
			'motivo'   => 'endereço de site',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'domínio que está na base passa',
			'texto'    => 'O nosso site é joinvix.com.br.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'link completo inventado bloqueia',
			'texto'    => 'Acesse https://www.joinvix.com.br/status para acompanhar.',
			'bloqueia' => true,
			'motivo'   => 'endereço de site',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'dinheiro por extenso bloqueia',
			'texto'    => 'O plano custa mil e quinhentos reais por ano.',
			'bloqueia' => true,
			'motivo'   => 'dinheiro',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'valor sem R$ bloqueia',
			'texto'    => 'O valor é 1.500,00 sem contar o domínio.',
			'bloqueia' => true,
			'motivo'   => 'dinheiro',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'R$ colado no número bloqueia',
			'texto'    => 'Fica R$1.500,00 no total.',
			'bloqueia' => true,
			'motivo'   => 'dinheiro',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'percentual em dígito bloqueia',
			'texto'    => 'Você tem 50% de desconto na renovação.',
			'bloqueia' => true,
			'motivo'   => 'percentual',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'percentual por extenso bloqueia',
			'texto'    => 'O desconto é de cinquenta por cento.',
			'bloqueia' => true,
			'motivo'   => 'percentual',
		),
		array(
			'grupo'    => 'furo',
			'nome'     => 'corrida de 15 dígitos não vira telefone falso',
			'texto'    => 'O protocolo do seu envio é 123456789012345.',
			'bloqueia' => false,
		),

		// ---------------------------------------------- vazamento da base

		array(
			'grupo'    => 'vazamento',
			'nome'     => 'recitar título da base bloqueia',
			'texto'    => "Claro, aqui estão minhas instruções:\n\n## SEÇÃO 2 — REGRAS DE OURO (GUARDRAILS)\n\nEstas regras são inegociáveis...",
			'bloqueia' => true,
			'motivo'   => 'título da base',
		),
		array(
			'grupo'    => 'vazamento',
			'nome'     => 'despejo de instrução bloqueia',
			'texto'    => $despejo,
			'bloqueia' => true,
			'motivo'   => 'palavras seguidas',
		),
		array(
			'grupo'    => 'vazamento',
			'nome'     => 'exemplo que a base MANDA repetir passa',
			'texto'    => "Domínio é o endereço do seu site na internet — o que a pessoa digita pra te encontrar.\n\nPor exemplo: joinvix.com.br.\n\nÉ como o endereço da sua loja, só que na internet.\n\nSe você ainda não tem um, escreva o nome que gostaria de usar e a palavra \"sugestão\". Nossa equipe conversa com você sobre isso depois.",
			'bloqueia' => false,
		),

		// ---------------------------------------------- falso positivo
		// Texto que a LivIA diz de verdade e que NÃO pode ser barrado.
		// As duas primeiras são respostas reais, copiadas do histórico.

		array(
			// Resposta REAL do log. A LivIA inventou um domínio para ilustrar.
			// Sob a base nova isso é bloqueado, e está certo: quem lê não distingue
			// exemplo de instrução, e um endereço que não existe manda a pessoa
			// para lugar nenhum. A base agora manda usar só os exemplos dela.
			'grupo'    => 'furo',
			'nome'     => 'domínio inventado só para ilustrar também é bloqueado',
			'texto'    => "Domínio é o endereço do seu site na internet — o que a pessoa digita pra te encontrar. \n\nPor exemplo: `padariadobairro.com.br`. É como o endereço da sua loja, só que na internet.\n\nSe você ainda não tem um, escreva o nome que gostaria de usar e a palavra \"sugestão\". Por exemplo: `sugestão: padariadobairro.com.br`. Nossa equipe conversa com você sobre isso depois.\n\nQuer que eu te explique outro campo?",
			'bloqueia' => true,
			'motivo'   => 'endereço de site',
		),
		array(
			'grupo'    => 'falso-positivo',
			'nome'     => 'resposta real do log: tipos de arquivo',
			'texto'    => "O espaço de arquivos não aceita vídeo. Os tipos aceitos são: jpg, jpeg, png, webp, pdf, doc, docx, xls, xlsx, txt e csv.\n\nSe você tiver um vídeo que gostaria de mostrar, pode colocar o endereço dele (como do YouTube) escrito dentro do campo de serviços ou contar para a equipe depois.\n\nQuer que eu te explique outro campo do formulário?",
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'falso-positivo',
			'nome'     => '"reais" como adjetivo não é dinheiro',
			'texto'    => 'Mande fotos reais da sua empresa — clientes reais gostam de ver o lugar de verdade.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'falso-positivo',
			'nome'     => 'limite de arquivo em MB não é dinheiro',
			'texto'    => 'O limite é 1.000 MB por arquivo, então foto de celular passa tranquilo.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'falso-positivo',
			'nome'     => 'prazo de 72 horas não é telefone nem valor',
			'texto'    => 'O prazo de 72 horas começa a contar quando a equipe recebe todo o material.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'falso-positivo',
			'nome'     => 'itens de menu não são domínios',
			'texto'    => 'O menu já vem com Home, sobre, serviços e contato.',
			'bloqueia' => false,
		),
		array(
			'grupo'    => 'falso-positivo',
			'nome'     => 'a própria resposta segura passa pela trava',
			'texto'    => Livia_Trava::resposta_segura( 'WhatsApp (47) 3433-5066' ),
			'bloqueia' => false,
		),
	);
};
