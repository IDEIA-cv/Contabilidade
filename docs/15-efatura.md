# 15. Fatura eletrónica (e-Fatura, DNRE)

A aplicação gera os documentos fiscais eletrónicos (DFE) de Cabo Verde e comunica-os à e-Fatura através do **Middleware** oficial da DNRE. Esta página resume as regras retiradas dos documentos oficiais e explica como estão implementadas (versão 0.6, esquema v9).

## Fontes

Os ficheiros oficiais estão em `resources/efatura/` e podem ser abertos na aplicação, no menu **Fatura eletrónica → Documentação oficial**:

| Ficheiro | Conteúdo |
|---|---|
| `docs/manual-tecnico-fatura-eletronica-v11.0.pdf` | Manual técnico v11.0 (14/03/2025): modelo de dados, IUD, contingência, DFA, API, Middleware |
| `docs/pedido-certificado-digital-tipos-de-documentos.pdf` | Tipos de documento a indicar no pedido do certificado |
| `docs/lista-motivos-nao-liquidacao-imposto.xlsx` | 21 motivos de isenção (código, descrição, menção obrigatória) |
| `docs/codigos-unidades-medidas.xls` | Unidades de medida (UN/ECE Rec. 20) |
| `xsd/EnvelopedSignature.xsd` e `xsd/common/*.xsd` | XSD oficial de 27/05/2024 (namespace `urn:cv:efatura:xsd:v1.0`) |
| `xsd/XML Fields Map.txt`, `xsd/Read Me.txt` | Mapa de campos e notas da DNRE |
| `xsd/saftcv1.01_01.xsd` | XSD do SAF-T CV 1.01_01 |

As listas foram convertidas para `database/efatura-codes.php` (isenções, unidades comuns com nome em português e as 1824 unidades não obsoletas).

Páginas oficiais: [e-Fatura](https://www.efatura.cv/docs/about/e-fatura/), [guias](https://www.efatura.cv/guide/), [Plataforma Eletrónica](https://pe.efatura.cv). Apoio da DNRE: helpdesk@efatura.cv, Linha Verde 8006666.

## Arquitetura escolhida

```
Aplicação ──XML sem assinatura (validado com o XSD)──► Middleware (no computador do contribuinte) ──assinado, OAuth──► e-Fatura
```

- O **Middleware** (downloads em `update.efatura.cv/{win,mac,linux}/mw-client/mwcore-efatura.zip`) usa os certificados importados na Plataforma Eletrónica, assina com XMLDSig/XAdES-BES (RSA-SHA256) e trata da autenticação OAuth.
- A aplicação gera o XML, valida-o com `DOMDocument::schemaValidate` contra o XSD oficial, junta-o num ZIP (Deflate) com o nome `<IUD>.xml` e envia-o em `POST {middleware}/v1/dfe` (campo multipart `file`), com os cabeçalhos `cv-ef-mw-core-transmitter-key` (chave de 64 carateres gerada no Middleware) e `cv-ef-repository-code`.
- Não guardamos certificados nem palavras-passe da DNRE. A chave do Middleware fica na base da empresa (`meta.efatura_mw_key`) e nunca é devolvida pela API.
- A ligação direta à API (`services.efatura.cv`, OAuth com PKCE) e a assinatura própria não estão implementadas: o Middleware é a via recomendada pela DNRE para software de faturação local.

## Tipos de documento

| Na aplicação | DFE | Código | Elemento XML | Notas |
|---|---|---|---|---|
| FT Fatura | FTE | 1 | `Invoice` | vencimento e NIB em `Payments` |
| FR Fatura-recibo | FRE | 2 | `InvoiceReceipt` | **exige NIF do cliente** |
| TV Talão de venda (novo) | TVE | 3 | `SalesReceipt` | consumidor final; identificação obrigatória a partir de 20 000 ECV |
| RC Recibo | RCE | 4 | `Receipt` | referências às faturas pagas |
| NC Nota de crédito | NCE | 5 | `CreditNote` | motivo e fatura de origem obrigatórios |
| ND Nota de débito | NDE | 6 | `DebitNote` | motivo e fatura de origem obrigatórios (nasce de uma fatura) |
| GE Guia de entrega / GT Guia de transporte | DTE | 7 | `Transport` | **numeração comum** (ver abaixo) |

Orçamentos e pro-formas não são DFE. As notas de devolução (DVE) e de lançamento (NLE) não existem ainda na aplicação.

## IUD e código QR

IUD de 45 carateres: `CV` + repositório (1) + AAMMDD (6) + NIF (9) + LED (5) + tipo (2) + número (9) + código aleatório (10) + dígito de controlo Luhn calculado sobre os 42 algarismos depois de `CV`. Verificado com o exemplo do manual (`CV1200520123456789000112345678901112345678904`).

- É atribuído na emissão (`Efatura::assignIud`) quando a e-Fatura está ativa, e guardado em `sales_docs.iud` (índice único).
- Repositórios: 1 Produção, 2 Homologação, 3 Teste. O IUD inclui o repositório: documentos emitidos em Teste não servem em Produção.
- O código QR (gerador próprio em `src/Qr.php`, sem dependências) aponta para `https://pe.efatura.cv/dfe/view/<IUD>`.

A chave de unicidade da DNRE é (NIF, ano, LED, tipo, número), sem a série. Por isso a guia de transporte usa o contador das guias de entrega (`Sales::SHARED_COUNTER`): GE 2026/1, GT 2026/2, GE 2026/3. Ficheiros com séries GT antigas já separadas mantêm-nas.

## Regras de dados aplicadas na emissão

- Emitente com NIF, nome, morada, email e telefone (Empresa e cópias). O que falta aparece no menu e-Fatura.
- Adquirente (`ReceiverParty`) com NIF, nome e morada, exceto no talão de venda abaixo de 20 000 ECV.
- Isenções: código da lista oficial (`TaxTypeCode="NA"` + `TaxExemptionReasonCode`). Artigos, tipos de artigo e linhas guardam o código; os textos antigos conhecidos são convertidos.
- Unidades: código UN/ECE (`EA` unidade, `KGM`, `HUR`, `E48` serviço…). Abreviaturas antigas (UN, KG, H, SERV…) são convertidas; unidades desconhecidas são recusadas nos documentos fiscais.
- Motivo das notas (`IssueReasonCode`): artigo 65.º do CIVA (2, 3, 4, 6, 7, 8, 9), DD débito de despesas, DRP desconto por rappel, conforme o tipo.
- Referências: `FiscalDocument` com o IUD do documento de origem, ou `IsOldDocument="true"` com `tipo/ano/série/número` para documentos sem IUD.
- Meios de pagamento (UN/ECE 4461): numerário 10, transferência 31, cartão 48, cheque 20, outro 1.
- XML sem comentários, sem espaços supérfluos e sem prefixos de namespace, como recomenda a DNRE.

## Modos de emissão e contingência

- **Online (1)**: o documento é enviado até 24 horas depois da data/hora de emissão.
- **Offline (2)**: enviado mais tarde (até 7 dias), com o bloco `Contingency` (LED, data, hora e motivo: 4 sem Internet, 1 serviço indisponível, 5 carimbo temporal, 0 outro).
- Se o Middleware não responder, o documento continua **por comunicar** e passa a modo offline.
- Enquanto não estiver autorizado, a impressão mostra "EMITIDO EM CONTINGÊNCIA OFFLINE / Pendente de Autorização" e o aviso "Este documento não confere direito a dedução de imposto, enquanto o documento associado não for transmitido e autorizado pela Administração Fiscal" (manual, 6.13). Com IUD, imprime sempre o QR, o IUD e "Consulta DFE em efatura.cv".

## Estados na aplicação (`sales_docs.efatura_status`)

| Estado | Significado |
|---|---|
| `pendente` | Por comunicar |
| `autorizado` | Aceite pela e-Fatura |
| `rejeitado` | Recusado (mensagem da DNRE em `efatura_message`) |
| `erro` | Não foi possível gerar ou validar o XML (dados em falta) |
| `excluido` | Não comunicar (anterior à data de início, ou marcado pelo utilizador) |

## Documentos recebidos (compras)

Menu **Fatura eletrónica → Documentos recebidos** (`src/EfaturaReceived.php`). O utilizador descarrega da Plataforma Eletrónica os DFE em que a empresa é adquirente (XML, ou um ZIP com vários, até 500) e envia o ficheiro. Primeiro vê o que vai acontecer a cada documento; depois importa.

| DFE | Nas compras |
|---|---|
| FTE fatura, NDE nota de débito | FC fatura de fornecedor |
| FRE fatura-recibo, TVE talão | FP despesa paga (meio de pagamento do DFE) |
| NCE nota de crédito | NF ligada à fatura de origem (pelo IUD da referência ou pelo número do fornecedor) |
| RCE recibo, DTE guia, outros | ignorados |

- Ignora documentos emitidos pela própria empresa e documentos cujo adquirente é outro NIF.
- Duplicados: pelo IUD (`purchase_docs.iud`, índice único) ou pelo mesmo número do fornecedor já registado à mão.
- O fornecedor é criado a partir do emitente se não existir (um cliente com o mesmo NIF passa a "ambos").
- As linhas mantêm quantidade, preço e desconto quando o cálculo dá o mesmo valor líquido; senão entram com quantidade 1 e o valor líquido do DFE. O IVA usa o código dedutível da mesma taxa; isenções usam o código isento.
- Com **Registar logo**, os documentos de fornecedores com conta de gastos habitual são registados (lançamento incluído); os restantes ficam em rascunho com a conta 6268 para o utilizador confirmar.
- Erros explicados: nota de crédito sem a fatura de origem, data sem exercício, taxa de IVA sem código dedutível. Retenções na fonte são assinaladas para confirmação.
- Cada importação fica no histórico de importações e no registo de auditoria.

## Conferência do IVA

Menu **Fatura eletrónica → Conferência do IVA** (`Efatura::conference`). Para cada mês do exercício:

- **Vendas**: IVA dos documentos fiscais emitidos (autorizados, por comunicar, excluídos; notas de crédito a abater) contra o IVA liquidado nos lançamentos.
- **Compras**: IVA das faturas de fornecedores com IUD (vindas da e-Fatura) e das registadas à mão, contra o IVA dedutível nos lançamentos.
- A contabilidade conta as linhas com código de IVA liquidado ou dedutível com taxa, fora do diário de apuramento.
- Os meses antes da data de início da e-Fatura aparecem como "antes da adesão" e não entram nas diferenças.
- Lista das faturas de fornecedores com IVA sem documento da e-Fatura, para importar o XML e confirmar o direito à dedução.

## Menu "Fatura eletrónica"

- **Documentos recebidos** e **Conferência do IVA**: ver acima.
- **Estado e envio**: contagens por estado, lista filtrável, verificação de cada documento com o XSD, descarga do XML, envio dos selecionados ou de todos os pendentes, "Não comunicar" e "Repor na fila".
- **Configuração**: ativar, data de início, repositório, LED, código do software (até 10 letras ou algarismos, padrão do XSD), NIF do transmissor, motivo de contingência, endereço e chave do Middleware, verificação do certificado HTTPS, "Testar ligação".
- **Documentação oficial**: ficheiros locais, páginas oficiais, downloads do Middleware e os passos de adesão.
- Na página de cada documento de venda: estado, IUD, resposta, "Verificar com o XSD oficial", "Descarregar XML" e "Enviar à e-Fatura".

## Passos para o contribuinte

1. Registo na Plataforma Eletrónica com NIF, email e telemóvel do cadastro fiscal.
2. Pedido de certificados (menu "Certificado Digital") com os tipos FTE, FRE, TVE, RCE, NCE, NDE, DTE.
3. Registo do LED e do software; anotar o número do LED e o código do software.
4. Instalar o Middleware, configurar transmissor e emitente, copiar a chave de transmissor.
5. Na aplicação: completar os dados da empresa, preencher a configuração com o repositório **Teste**, ativar e testar a ligação.
6. Homologação: emitir e enviar documentos de teste. Depois mudar para **Produção** e indicar a data de início.

## Código

| Ficheiro | Papel |
|---|---|
| `src/Efatura.php` | Configuração, códigos oficiais, IUD, `buildXml`, `validate`, fila, `send` pelo Middleware, `test` |
| `src/EfaturaReceived.php` | Leitura dos DFE recebidos (XML/ZIP), pré-visualização e criação das compras |
| `src/Qr.php` | Código QR (modo byte, ECC M, versões 1–10) em SVG |
| `src/Sales.php` | Talão de venda, NIF na FR, contador comum das guias, motivo e origem das notas, códigos de isenção e unidades, IUD na emissão |
| `src/SalesPrint.php` | IUD, QR e avisos de contingência na impressão |
| `assets/js/views/efatura.js` | Menu e-Fatura |
| `tests/unit/EfaturaTest.php` | IUD, códigos, configuração, XML de todos os tipos validado com o XSD, envio simulado (autorizado, rejeitado, sem ligação), impressão, migração v7→v8 |
| `tests/unit/EfaturaReceivedTest.php` | Documentos recebidos, conferência do IVA, migração v8→v9 |

## Limitações conhecidas

- O formato exato da resposta do Middleware (`succeeded`/`messages`) foi deduzido da documentação da API; a interpretação (`Efatura::parseResponse`) pode precisar de ajuste na homologação.
- Os DFE recebidos são importados a partir do ficheiro descarregado; a consulta automática pelo Middleware (`GET /v1/dfe`) ainda não existe.
- O modo OFF (sem sistema, com IUC) não se aplica: a aplicação está sempre disponível localmente.
- Clientes estrangeiros: o NIF é enviado tal como está, com o país da ficha; convém confirmar na homologação.
