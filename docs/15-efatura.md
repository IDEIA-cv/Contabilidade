# 15. e-Fatura (DNRE): notas de preparação

Notas recolhidas em 27/11/2026 de https://www.efatura.cv/docs/about/e-fatura/, dos guias em https://www.efatura.cv/guide/ e da primeira parte do **Manual Técnico da Fatura Eletrónica v11.0** (14/03/2025). Ainda falta ler: secções 7.19 a 7.21 (contingência, assinatura, tipos de dados), 9 (versão XML, namespace), 10 (API) e 11 (Middleware), e o XSD de 27/05/2024.

## O que é

- Todos os sujeitos passivos credenciados emitem documentos fiscais eletrónicos (DFE), assinados pelo emitente e autorizados pela administração tributária em tempo real, de forma síncrona e por HTTPS.
- A representação impressa (DFA) tem de levar o IUD e um QR Code.

## Tipos de documento (código DTC-E)

| Código | DFE | Sigla | Na aplicação |
|---|---|---|---|
| 1 | Fatura eletrónica | FTE | FT |
| 2 | Fatura-recibo eletrónica | FRE | FR |
| 3 | Talão de venda eletrónico | TVE | (novo) |
| 4 | Recibo eletrónico | RCE | RC |
| 5 | Nota de crédito eletrónica | NCE | NC |
| 6 | Nota de débito eletrónica | NDE | ND |
| 7 | Documento de transporte eletrónico | DTE | GE, GT |
| 8 | Nota de devolução | DVE | (novo) |
| 9 | Nota de lançamento | NLE | (novo) |

## IUD (identificador único, 45 caracteres)

`CV` (2) + repositório (1) + ano AA (2) + mês (2) + dia (2) + NIF (9) + LED (5) + tipo (2) + n.º do documento (9) + código aleatório (10) + dígito de controlo Luhn (1).

- Repositório: 1 = Principal, 2 = Homologação, 3 = Teste.
- (NIF, ano, LED, tipo, n.º) tem de ser único.

## LED, série e numeração

- **LED** ("Lógica de Emissão de DFE"): até 5 dígitos, registado previamente na plataforma (pe.efatura.cv) antes de emitir.
- Série: até 20 caracteres `A-Z a-z 0-9 - _`.
- N.º: inteiro de 1 a 999 999 999.
- A sequência e o recomeço anual não são validados na transmissão (diferido). A aplicação já os garante.

## Assinatura e certificados

- Certificado da hierarquia ICP-CV, chave RSA, hash SHA-256. O elemento `Signature` é obrigatório.
- O emitente precisa de um "Certificado Digital Qualificado de Representação Coletiva / Selo Eletrónico".
- O transmissor precisa de um "Certificado Digital Qualificado de Autenticação Web (SSL-EV)".
- O formato exato do envelope (XAdES?) ainda não foi confirmado.

## Modos de emissão e contingência

- **Online** (1): data e hora até 1 h à frente e até 24 h atrás da hora do sistema.
- **Offline** (2) e **contingência (OFF)**: até 7 dias atrás.
  - Em OFF usa-se o IUC (Identificador Único de Contingência) e um motivo.
  - O cliente recebe obrigatoriamente um DFA com "EMITIDO EM CONTINGÊNCIA OFFLINE / Pendente de Autorização".
  - Estes documentos não dão direito a dedução até serem autorizados.
- O prazo de envio após contingência está na secção 7.19, ainda não lida.

## QR Code

URL `https://pe.efatura.cv/dfe/view/<IUD>`. Pode ser consultado sem autenticação durante 1 h após a autorização.

## Outros códigos

- **TaxTypeCode**: NA, IVA, IS, IR.
- **TaxExemptionReasonCode**: da "Lista de Motivos de Não Liquidação de Imposto" (xlsx no site).
- **UnitCode**: da "Lista de Unidades de Medidas" (xls).
- **LineTypeCode**: N, C, D, I.
- **ReceiptTypeCode**: 1 Comercial, 2 Serviço, 3 Comercial e Serviço, 4 Renda.
- O código do software tem de estar registado no SFECV.
- O emitente tem de ter email e telefone.

## Adesão (guia adesao-pe)

1. Registo em https://pe.efatura.cv/register com NIF, email e telemóvel iguais aos do cadastro fiscal.
2. Confirmação por email.
3. Palavra-passe temporária por SMS.
4. Mudança da palavra-passe.
5. Pedido de certificados no menu "Certificado Digital".
6. Testes em **homologação**.
7. Passagem automática a produção.

A API usa OAuth (scopes listados na secção 10.1 do manual). Apoio: helpdesk@efatura.cv, Linha Verde 8006666.

## Ficheiros oficiais

- Manual v11.0: https://www.efatura.cv/assets/files/manual-tecnico-da-fatura-eletronica-v11.0-be67e62c7fb34552fbcc8eeea966e217.pdf
- XSD e-Fatura (27/05/2024): https://www.efatura.cv/assets/files/2024-05-27-XML-XSD-497f4b5a8e89ac2a3f766bb0cb85f9d9.zip
- XSD SAF-T CV 1.01_01: https://www.efatura.cv/assets/files/saftcv1.01_01.xsd-4578ec7766d4820beb36ad57c7db399c.zip
- Motivos de isenção: https://www.efatura.cv/assets/files/Lista-de-Motivos-de-Nao-Liquidacao-de-Imposto-7745511b183179e6e111a9e3f1b559f4.xlsx
- Unidades: https://www.efatura.cv/assets/files/codigos-de-unidades-de-medidas-827b26a9ab0b39dae05ffd6aaa48d021.xls

## O que a aplicação pode fazer já, sem credenciais

1. Configurar o LED e o repositório (teste/homologação/produção) em Empresa.
2. Gerar o IUD com dígito Luhn na emissão e imprimir o QR Code no documento.
3. Usar as listas oficiais de motivos de isenção e de unidades.
4. Gerar o XML do DFE validado contra o XSD (assim que o XSD estiver na pasta do projeto).
5. Fila de envio com estados (por comunicar, enviado, autorizado, rejeitado) e modo de contingência.

O envio real (assinatura com o certificado e chamada OAuth) só pode ser testado com conta em homologação.
