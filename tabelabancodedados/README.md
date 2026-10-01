# Scripts de Banco de Dados - Sistema MustaBarber

## Ordem de Execução dos Scripts

Para configurar corretamente o sistema de controle de permissões e perfis, execute os scripts SQL na seguinte ordem:

### 1. Tabela Perfil
```bash
psql -U seu_usuario -d mustabarber -f perfil.sql
```
Cria a tabela de perfis e insere os perfis padrão:
- Administrador
- Barbeiro Plano Básico
- Barbeiro Plano Médio
- Barbeiro Plano Avançado
- Cliente

### 2. Tabela Permissão
```bash
psql -U seu_usuario -d mustabarber -f permissao.sql
```
Cria a tabela de permissões e insere as permissões padrão para cada perfil.

### 3. Tabela Assinaturas
```bash
psql -U seu_usuario -d mustabarber -f assinaturas.sql
```
Cria a tabela de controle de assinaturas e pagamentos dos barbeiros.

### 4. Atualização da Tabela Usuarios
```bash
psql -U seu_usuario -d mustabarber -f usuarios_update.sql
```
Adiciona a coluna `ultimo_acesso` e cria o relacionamento com a tabela perfil.

## Estrutura das Tabelas

### Perfil
- `codigo`: Identificador único
- `nome`: Nome do perfil
- `descricao`: Descrição do perfil
- `data_cadastro`: Data de cadastro

### Permissão
- `codigo`: Identificador único
- `codigo_perfil`: Relacionamento com perfil
- `nome`: Nome da permissão
- `modulo`: Módulo do sistema
- `acao`: Ação permitida
- `descricao`: Descrição da permissão
- `data_cadastro`: Data de cadastro

### Assinaturas
- `codigo`: Identificador único
- `codigo_barbeiro`: Relacionamento com usuário
- `codigo_perfil`: Relacionamento com perfil/plano
- `data_inicio`: Data de início da assinatura
- `data_vencimento`: Data de vencimento
- `valor_mensalidade`: Valor da mensalidade
- `status_pagamento`: Status (1=Pendente, 2=Pago, 3=Vencido, 4=Cancelado)
- `data_pagamento`: Data do pagamento
- `forma_pagamento`: Forma de pagamento
- `observacoes`: Observações
- `data_cadastro`: Data de cadastro
- `data_atualizacao`: Data da última atualização

### View: vw_assinaturas_completa
View que facilita consultas mostrando:
- Dados completos do barbeiro
- Nome do plano
- Dias para vencimento
- Se está vencido
- Status do pagamento em texto

## Funcionalidades do Painel Administrativo

O painel administrativo permite:
- ✅ Visualizar todos os barbeiros do sistema
- ✅ Ver quando cada barbeiro começou a utilizar o sistema
- ✅ Verificar último horário de acesso
- ✅ Verificar status de pagamento da mensalidade
- ✅ Ver data de vencimento
- ✅ Ver quantos dias faltam para vencimento
- ✅ Ver qual plano o barbeiro está usando
- ✅ Filtrar por plano e status de pagamento
- ✅ Visualizar receita mensal total
- ✅ Ver quantas assinaturas estão ativas ou vencidas

## Perfis e Permissões

### Administrador (Perfil 1)
Acesso total ao sistema incluindo:
- Todos os módulos (Dashboard, Agendamentos, Clientes, Serviços, Produtos, Financeiro, Horários)
- Painel administrativo
- Gestão de usuários

### Barbeiro Plano Básico (Perfil 2)
Acesso básico:
- Dashboard
- Agendamentos (visualizar e criar)
- Clientes (visualizar e criar)
- Serviços (visualizar)
- Horários (visualizar)

### Barbeiro Plano Médio (Perfil 3)
Acesso médio:
- Todas as funcionalidades do Plano Básico
- Produtos (visualizar)
- Financeiro básico (visualizar)
- Configurar horários
- Criar serviços

### Barbeiro Plano Avançado (Perfil 4)
Acesso completo (exceto painel administrativo):
- Todas as funcionalidades do sistema
- Gestão completa de produtos (incluindo estoque)
- Gestão financeira completa
- Todos os módulos com permissão de criar, editar e excluir

### Cliente (Perfil 5)
Acesso limitado:
- Visualizar apenas seus próprios agendamentos
- Criar agendamento para si mesmo
- Cancelar seus próprios agendamentos

## Notas Importantes

1. **Ordem de execução**: É importante executar os scripts na ordem indicada devido às dependências entre as tabelas.

2. **Perfil padrão**: A tabela usuarios já possui uma coluna `perfil` que será relacionada com a tabela perfil criada.

3. **Administrador**: Para ter acesso ao painel administrativo, o usuário deve ter perfil = 1 (Administrador).

4. **Segurança**: Os endpoints PHP verificam se o usuário logado é administrador antes de permitir acesso aos dados.

5. **View vw_assinaturas_completa**: Facilita muito as consultas ao combinar dados de múltiplas tabelas.
