# Requisições de referência para a API do Gemini.
#
# A chave NUNCA fica escrita aqui. Carregue do .env antes de rodar:
#
#   bash:        export $(grep -v '^#' .env | xargs)
#   powershell:  Get-Content .env | ForEach-Object { if ($_ -match '^([^#=]+)=(.*)$') { Set-Item "env:$($matches[1].Trim())" $matches[2].Trim() } }


# ---------------------------------------------------------------- 1. chamada simples
# Usada por Livia_Gemini::gerar() no plugin e por chamar_gemini() em ferramentas/livia.py.

curl "https://generativelanguage.googleapis.com/v1beta/models/$GEMINI_MODEL:generateContent" \
  -H 'Content-Type: application/json' \
  -H "X-goog-api-key: $GEMINI_API_KEY" \
  -X POST \
  -d '{
    "contents": [
      { "role": "user", "parts": [ { "text": "o que e dominio" } ] }
    ],
    "generationConfig": { "temperature": 0.2, "topP": 0.8, "maxOutputTokens": 512 }
  }'


# ---------------------------------------------------------------- 2. streaming (SSE)
# É a que Livia_Gemini::gerar_stream() consome via cURL com CURLOPT_WRITEFUNCTION.
# Repare no ?alt=sse: sem ele a API devolve um array JSON, não eventos.

curl -N "https://generativelanguage.googleapis.com/v1beta/models/$GEMINI_MODEL:streamGenerateContent?alt=sse" \
  -H 'Content-Type: application/json' \
  -H "X-goog-api-key: $GEMINI_API_KEY" \
  -X POST \
  -d '{
    "contents": [
      { "role": "user", "parts": [ { "text": "o que e dominio" } ] }
    ],
    "generationConfig": { "temperature": 0.2, "maxOutputTokens": 512 }
  }'


# ---------------------------------------------------------------- 3. o modelo existe?
# Confere se o ID em GEMINI_MODEL é válido para esta chave. Um ID errado vira 404
# em toda resposta, para todo cliente. O botão "Testar chave e modelo" da configuração faz esta checagem.

curl "https://generativelanguage.googleapis.com/v1beta/models/$GEMINI_MODEL" \
  -H "X-goog-api-key: $GEMINI_API_KEY"
