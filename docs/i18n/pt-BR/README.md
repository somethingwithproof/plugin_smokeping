<!-- Source: README.md; SHA256: 17905aa1eac76a7d10dab526734605629fa3d1ada56397642ad4154c5be1feff -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../images/logo_dark.png">
    <img src="../../images/logo.png" width="200" height="200" alt="Plugin SmokePing para Cacti: um cacto usando um emblema com um gráfico do SmokePing">
  </picture>
</p>

# Plugin SmokePing para Cacti

[![CI](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml/badge.svg)](https://github.com/somethingwithproof/plugin_smokeping/actions/workflows/plugin-ci-workflow.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](../../../LICENSE)
![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)
![Cacti >= 1.2.31](https://img.shields.io/badge/cacti-%3E%3D1.2.31-2e7d32.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=coverage)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_smokeping&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_smokeping)
![SmokePing: read-only](https://img.shields.io/badge/smokeping-read--only-6b7280.svg)

Um plugin do Cacti 1.2 que traz as medições de latência e perda do SmokePing para o Cacti como dispositivos, fontes de dados e gráficos comuns. Os gráficos de fumaça ficam então ao lado do dispositivo ao qual pertencem, sob as permissões do Cacti, e o Thold e o Maint podem gerar alertas e suprimi-los. Trata-se de uma implementação independente para o Cacti e não faz parte nem é endossada pelo [SmokePing](https://oss.oetiker.ch/smokeping/), cujos autores originais são Tobias Oetiker e Niko Tyni. O plugin não inclui nem executa o código do SmokePing: ele lê a configuração e os arquivos RRD que o SmokePing grava, e seus modelos de gráfico são construídos para desenhar as mesmas séries dos próprios gráficos do SmokePing.

> **Status: em desenvolvimento. O modo de observação (fase 1) está implementado na `main` e publicado como pré-lançamento 1.0.0-rc.1.** O plugin lê a configuração e os arquivos RRD do SmokePing, mostra o estado deles, cria dispositivos, fontes de dados e gráficos do Cacti para eles e alimenta o Thold e o Maint. Ele não grava nada no SmokePing. O modo gerenciado (o plugin gravando a configuração do SmokePing) e os coletores remotos são fases posteriores, ainda não iniciadas, e não estão neste lançamento. Pilotos em instalações reais e uma correspondência mais próxima à legenda do gráfico do SmokePing ainda estão em aberto no marco 4. Consulte [docs/install.md](../../install.md) para começar.

## Perfis de acesso

| Realm | Acesso |
| --- | --- |
| SmokePing View | Páginas de monitoramento existentes. |
| SmokePing Operator | Monitoramento mais a página de Operações e reconhecimentos pessoais de triagem. |
| SmokePing Admin | Acesso de Operator mais as ações administrativas e de configuração existentes. |

Após copiar os arquivos de atualização, abra a página Plugins como administrador para registrar
o novo realm. Atribua o realm Operator explicitamente em **Configuration > Users** ou nos grupos de usuários.
As atualizações mantêm os IDs de realm e as concessões existentes de View e Admin. O acesso Admin
herda o acesso Operator, e o acesso Operator herda o acesso View em cada requisição;
isso não adiciona concessões armazenadas a usuários ou grupos. As configurações do plugin também exigem
acesso SmokePing Admin além da permissão Settings do Cacti.

As permissões de dispositivo do Cacti ainda limitam as linhas e ações disponíveis para cada usuário; objetos não mapeados exigem um administrador.
Credenciais, configuração, mapeamento, exclusão e outras alterações administrativas
permanecem nas páginas administrativas. Os reconhecimentos duram apenas na sessão atual
do usuário, por no máximo uma hora e somente enquanto a mesma observação permanecer
recente. Observações novas ou alteradas os invalidam. Eles nunca marcam uma medição
com falha como saudável nem suprimem alertas.

## Capturas de tela

Importado de uma rede SmokePing 2.8.2 em produção para o Cacti 1.2.31, últimas 12 horas. O dispositivo de cada alvo possui um gráfico de fumaça para o master e um para cada slave (`lon1`, `sgp1`), organizado sob sua seção do SmokePing na árvore de gráficos.

![Visualização em árvore do Cacti do alvo Bangalore: gráficos de fumaça do master e dos slaves lon1 e sgp1](../../images/screenshot-tree-asia.png)

![Visualização em árvore do Cacti do alvo London: o slave lon1 na mesma região mostra latência em microssegundos com fumaça visível](../../images/screenshot-tree-europe.png)

## Por quê

O SmokePing mede bem a distribuição de latência e a perda de pacotes, e seus gráficos de fumaça mostram variações que um gráfico apenas com a média oculta. Executado em paralelo com o Cacti, ele deixa três lacunas:

- **Controle de acesso separado.** O CGI do SmokePing não sabe nada sobre usuários, grupos ou permissões de dispositivo do Cacti.
- **Sem contexto do dispositivo.** Contadores de latência e de interface do mesmo dispositivo vivem em ferramentas diferentes.
- **Sem caminho para os alertas do Cacti.** O Thold e o Maint funcionam em fontes de dados e dispositivos do Cacti, portanto os alertas do SmokePing são configurados e suprimidos separadamente.

O plugin preenche essas lacunas representando cada alvo do SmokePing como objetos do Cacti que apontam para os arquivos RRD existentes do SmokePing, além de um pequeno conjunto de valores resumidos coletados por polling. Ele reutiliza os gráficos, as permissões, o Thold e o Maint do Cacti em vez de construir versões paralelas deles. O suporte a coletores de dados remotos do Cacti está planejado para a fase 3 e não está neste lançamento.

## Escopo e status

| Fase | Escopo | Marcos | Status |
|---|---|---|---|
| 0, pesquisa | Matriz de compatibilidade, confirmação de comportamento upstream, prova de conceito de fidelidade de gráfico | [M1](https://github.com/somethingwithproof/plugin_smokeping/milestone/1) | Achados em `docs/research`; algumas issues de pesquisa estão abertas |
| 1, observação | Importação de inventário, dispositivos do Cacti, gráficos de fumaça e resumo, alertas do Thold, supressão do Maint, página de saúde | [M2](https://github.com/somethingwithproof/plugin_smokeping/milestone/2), [M3](https://github.com/somethingwithproof/plugin_smokeping/milestone/3), [M4](https://github.com/somethingwithproof/plugin_smokeping/milestone/4) | Publicado como 1.0.0-rc.1; pilotos em aberto |
| 2, gerenciado | Namespace de configuração pertencente ao Cacti, perfis de sonda ICMP e TCP, revisões, validação, recarregamento, reversão | [M5](https://github.com/somethingwithproof/plugin_smokeping/milestone/5) | Planejado, não iniciado |
| 3, remoto | SmokePing em coletores de dados remotos do Cacti, slaves como locais de medição | [M6](https://github.com/somethingwithproof/plugin_smokeping/milestone/6) | Planejado, não iniciado |

Fora do escopo: um mecanismo de alertas próprio do plugin, um mecanismo de relatórios, um daemon ou protocolo de rede personalizado, verificações gerenciadas de HTTP/DNS/serviço (o servcheck cobre isso) e gravação nos arquivos RRD do SmokePing.

## Metas de compatibilidade

| Componente | Alvo |
|---|---|
| Cacti | 1.2.31 ou posterior na série 1.2.x, sem patches no core |
| PHP | 8.2 ou posterior; `setup.php` permanece analisável no 8.0 para que um runtime sem suporte receba uma recusa em vez de uma interface do Cacti quebrada |
| SmokePing | 2.9.0, 2.8.2 e 2.7.3 (somente Ubuntu 22.04, até 2027-06-01) |
| Thold, Maint | Nenhuma alteração necessária |
| Séries | 10.000 por instalação com 20 pings e passo de 300 s ([desempenho](../../performance.md#supported-series-count)) |

## Documentação

| | |
|---|---|
| [Instalação](../../install.md) | Requisitos, montagem somente leitura do diretório de dados do SmokePing, instalação, contêineres, versões suportadas |
| [Operação](../../operations.md) | O que cada estado significa e suas evidências, verificações de integridade, comportamento quando as entradas falham |
| [Adicionar alvos ao Cacti](../../provisioning.md) | Um alvo, vários dispositivos, revinculação, permissões |
| [Alertas](../../alerting.md) | Valores resumidos, durações do Thold, obsolescência, dados atrasados, sobreposição com os alertas do SmokePing, Maint |
| [Formato de configuração](../../configuration-format.md) | Exportação e importação em YAML e XML |
| [Backup e restauração](../../backup-restore.md) | O que fazer backup e como uma restauração é retomada |
| [Desempenho](../../performance.md) | Medições locais e no runner de lançamento em 100, 1.000 e 10.000 séries, e a contagem de séries suportada: 10.000 por instalação |
| [Qualificação de falhas](../../failure-qualification.md) | Os cenários de falha e como cada um é testado |
| [Lançamento](../../RELEASING.md) | O procedimento de lançamento: o pull request, a tag assinada, o que o workflow faz, como verificar o resultado |
| [Arquivo de lançamentos](../../release.md) | Versão, changelog e o arquivo de lançamentos |
| [Testes](../../testing.md) | As suítes, o que a CI executa e a suíte ao vivo contra um SmokePing real |

## Design

- [docs/PLAN.md](../../PLAN.md) é o plano completo: arquitetura, fatos upstream dos quais depende (com fontes), modelo de estado, limites de segurança, aritmética de desempenho, testes e riscos.
- [docs/decisions/](../../decisions) contém registros de decisão de arquitetura para escolhas já feitas.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) mapeia cada ID de requisito para sua issue.

```mermaid
flowchart LR
    SP[Daemon do SmokePing] --> RRD[(Arquivos RRD)]
    RRD -->|somente leitura| R[Leitor no poller adjacente do Cacti]
    RRD -->|somente leitura| G[Modelos de gráfico do Cacti]
    R --> SS[Fontes de dados de resumo do script server]
    SS -->|poller_output| T[Thold e Maint]
    G --> P[Permissões de gráfico do Cacti]
```

## Como o trabalho é acompanhado

- **Marcos** seguem os marcos do plano. Cada um possui uma issue épica listando suas sub-issues e evidências de saída.
- **Issues** são tipificadas por rótulo: `type: requirement`, `type: research`, `type: decision`, `type: epic`. As issues de requisito definem requisitos numerados e testáveis (`REQ-AREA-NNN`) e critérios de aceitação.
- **Rótulos** também carregam `area:`, `phase:`, `priority:` e `status:`. Consulte [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- O [quadro do projeto](https://github.com/users/somethingwithproof/projects/6) mostra todas as issues em todos os marcos.

## Contribuição

Leia [CONTRIBUTING.md](../../../CONTRIBUTING.md). Os commits precisam de uma assinatura DCO. A discussão de design acontece nas issues; decisões em aberto carregam o rótulo `type: decision`.

## Segurança

Relate vulnerabilidades de forma privada; consulte [SECURITY.md](../../../SECURITY.md).

## Licença

GNU General Public License v2.0, a mesma licença do Cacti e de seus plugins. Consulte [LICENSE](../../../LICENSE).
