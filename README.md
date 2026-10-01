# MustaBarber

Sistema SaaS de gestão para barbearias, salões, estéticas e profissionais de beleza, desenvolvido pela **Mustech Tecnologia**.

O dono da barbearia cria a conta, escolhe um plano, cadastra serviços e horários. Depois disso, os clientes agendam sozinhos por um link, e a equipe acompanha a agenda, o financeiro e o estoque, tudo pelo celular.

## Funcionalidades

- **Agendamento online:** link público para o cliente agendar, com horários flexíveis, intervalo de almoço, bloqueios de agenda e um segundo dia de atendimento
- **Painel do barbeiro e da barbearia:** agenda do dia, detalhes do atendimento, alteração e cancelamento
- **Gestão de clientes, serviços e produtos**, com controle de estoque
- **Financeiro:** transações, formas de pagamento e dashboard de faturamento
- **Permissões por perfil:** administrador, barbearia e barbeiro
- **Assinaturas e cobrança recorrente** pela API do **Asaas**, com webhook, troca de plano e emissão de nota fiscal
- **Notificações por WhatsApp** pela **Evolution API**
- **Programa de indicação (embaixadores):** quem indica ganha recompensa quando o indicado paga
- **PWA:** dá para instalar no celular (manifest + service worker)
- **LGPD:** política de privacidade, termos de uso, política de cookies e página de direitos do titular

## Stack

| Camada | Tecnologia |
|---|---|
| Front-end | HTML5, CSS3, JavaScript (vanilla), PWA |
| Back-end | PHP 8 (endpoints por módulo em `bancodedados/`) |
| Banco de dados | PostgreSQL (PDO com reconexão automática) |
| Integrações | Asaas (pagamentos), Evolution API (WhatsApp) |

## Estrutura

```
├── index.html            # Landing page
├── login.html
├── paginas/              # Telas do sistema (agenda, clientes, financeiro…)
├── bancodedados/         # API em PHP, organizada por módulo
│   ├── agendamentos/
│   ├── pagamentos/       # Asaas: cobrança, webhook, planos
│   ├── whatsapp/         # Evolution API
│   └── DatabaseAssistente.php   # Conexão PDO (singleton)
├── tabelabancodedados/   # Scripts SQL de criação e migração
├── assets/
├── manifest.json
└── sw.js                 # Service worker (PWA)
```

## Como rodar localmente

1. PHP 8+ com a extensão `pdo_pgsql` e um PostgreSQL rodando.
2. Crie o banco e rode os scripts de `tabelabancodedados/`.
3. Copie `.env.example` e configure as variáveis no ambiente do servidor (Apache `SetEnv`, PHP-FPM ou `export`).
4. Suba um servidor:
   ```bash
   php -S localhost:8000
   ```

> As credenciais nunca ficam no código: tudo é lido por `getenv()`.

## Autor

**Ryan Morais**, fundador da Mustech Tecnologia
