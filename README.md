# 🔒 Certificate Ticket Plugin for GLPI

[![License](https://img.shields.io/badge/License-GPLv2+-blue.svg)](LICENSE)
[![GLPI Version](https://img.shields.io/badge/GLPI-11.0.x-green.svg)](https://glpi-project.org/)
[![Version](https://img.shields.io/badge/version-0.0.3-orange.svg)](https://github.com/celsocaninde/CertificateTicket/releases)

[🇧🇷 Português](#português) | [🇺🇸 English](#english)

---

## 🇧🇷 Português

### 📋 Descrição

Plugin para GLPI que **automatiza a criação de tickets** quando certificados digitais estão próximos da data de expiração. Ideal para equipes de TI que precisam monitorar e renovar certificados de forma proativa, evitando interrupções de serviço.

### ✨ Funcionalidades

- ✅ **Monitoramento Automático**: Verifica diariamente certificados próximos ao vencimento
- 🎫 **Criação Automática de Tickets**: Gera tickets automaticamente quando detecta certificados expirando
- 👥 **Atribuição Inteligente**: 
  - Atribui automaticamente o usuário técnico responsável
  - Adiciona o grupo técnico como atribuído
  - Inclui o grupo do certificado como observador
- 📧 **Notificações Personalizadas**: Tickets com descrição detalhada e emojis para melhor visualização
- 🔄 **Controle de Duplicatas**: Evita criar múltiplos tickets para o mesmo certificado
- ⚙️ **Integração com Entidades**: Respeita as configurações de alerta de cada entidade no GLPI

### 📦 Requisitos

- **GLPI**: Versão 11.0.0 ou superior (compatível com 11.0.x)
- **PHP**: Versão 8.1 ou superior
- **Banco de Dados**: MySQL/MariaDB

### 🚀 Instalação

#### Método 1: Download Manual

1. Baixe a [última versão](https://github.com/celsocaninde/CertificateTicket/releases)
2. Extraia o arquivo ZIP no diretório de plugins do GLPI:
   ```bash
   cd /var/www/html/glpi/plugins
   unzip certificateticket-x.x.x.zip
   ```

#### Método 2: Git Clone

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/celsocaninde/CertificateTicket.git certificateticket
```

#### Ativação

1. Acesse sua instância do GLPI
2. Navegue até: **Configurar → Plugins**
3. Localize o plugin **Certificate Ticket**
4. Clique em **Instalar** e depois em **Ativar**

### ⚙️ Configuração

Após a instalação, uma nova tarefa automática será criada:

1. Acesse: **Configurar → Ações Automáticas**
2. Localize a tarefa **certificateticket**
3. Configure conforme necessário (padrão: executa a cada 24 horas)

**Nota**: As configurações de alerta de certificados são feitas por entidade em:
- **Configurar → Entidades → [Sua Entidade] → Alertas**
- Configure "Enviar alertas de certificados antes do prazo" (padrão: 30 dias)

### 📊 Como Funciona

1. **Execução Diária**: A tarefa automática verifica certificados em todas as entidades
2. **Verificação**: Compara a data de expiração com o período de alerta configurado
3. **Criação de Ticket**: Se encontrar certificados próximos ao vencimento:
   - Cria um ticket com título formatado
   - Inclui detalhes completos do certificado
   - Atribui responsáveis automaticamente
   - Registra na base de dados para evitar duplicatas
4. **Atualização**: Se a data de expiração mudar, cria novo ticket

### 📝 Exemplo de Ticket Criado

**Título**: 🔒 Certificado Digital Expirando - Nome do Certificado (Serial)

**Conteúdo**:
```
⚠️ ATENÇÃO: Ação Necessária - Renovação de Certificado Digital

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 DETALHES DO CERTIFICADO:
   • Nome: example.com
   • Serial: ABC123456
   • Data de Expiração: 15/03/2026

🔔 AÇÃO REQUERIDA:
Este certificado digital está próximo da data de expiração...

✅ PRÓXIMOS PASSOS:
   1. Verificar a validade atual do certificado
   2. Iniciar o processo de renovação...
```

### 🗂️ Estrutura do Projeto

```
certificateticket/
├── src/
│   ├── CertificateTicket.php   # Classe principal e lógica de criação de tickets
│   └── Config.php              # Configurações (reservado para uso futuro)
├── hook.php                    # Instalação/desinstalação e criação de tabelas
├── setup.php                   # Configuração do plugin e hooks
├── certificateticket.xml       # Metadados para marketplace GLPI
├── certificateticket.svg       # Ícone do plugin
└── README.md                   # Este arquivo
```

### 🔧 Desenvolvimento Futuro

- [ ] Interface de configuração personalizada
- [ ] Opção para atribuir solicitante padrão
- [ ] Possibilidade de configurar template de ticket
- [ ] Suporte a múltiplos idiomas (i18n)
- [ ] Relatórios e dashboard de certificados

### 🐛 Problemas e Suporte

Encontrou um bug ou tem uma sugestão?

- [Abra uma issue](https://github.com/celsocaninde/CertificateTicket/issues)
- Entre em contato através do GitHub

### 📄 Licença

Este projeto está licenciado sob a **GPL v2+**. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

### 👨‍💻 Autor

**ADZ** - Desenvolvimento e manutenção

### 🙏 Contribuições

Contribuições são bem-vindas! Sinta-se à vontade para:
- Fazer fork do projeto
- Criar uma branch para sua feature (`git checkout -b feature/NovaFuncionalidade`)
- Commit suas mudanças (`git commit -m 'Adiciona nova funcionalidade'`)
- Push para a branch (`git push origin feature/NovaFuncionalidade`)
- Abrir um Pull Request

---

## 🇺🇸 English

### 📋 Description

GLPI plugin that **automates ticket creation** when digital certificates are approaching expiration. Ideal for IT teams that need to monitor and renew certificates proactively, avoiding service interruptions.

### ✨ Features

- ✅ **Automatic Monitoring**: Daily checks for certificates approaching expiration
- 🎫 **Automatic Ticket Creation**: Generates tickets automatically when detecting expiring certificates
- 👥 **Smart Assignment**: 
  - Automatically assigns the responsible technical user
  - Adds the technical group as assigned
  - Includes the certificate group as observer
- 📧 **Custom Notifications**: Tickets with detailed description and emojis for better visualization
- 🔄 **Duplicate Control**: Prevents creating multiple tickets for the same certificate
- ⚙️ **Entity Integration**: Respects alert configurations for each entity in GLPI

### 📦 Requirements

- **GLPI**: Version 11.0.0 or higher (compatible with 11.0.x)
- **PHP**: Version 8.1 or higher
- **Database**: MySQL/MariaDB

### 🚀 Installation

#### Method 1: Manual Download

1. Download the [latest release](https://github.com/celsocaninde/CertificateTicket/releases)
2. Extract the ZIP file to GLPI's plugins directory:
   ```bash
   cd /var/www/html/glpi/plugins
   unzip certificateticket-x.x.x.zip
   ```

#### Method 2: Git Clone

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/celsocaninde/CertificateTicket.git certificateticket
```

#### Activation

1. Access your GLPI instance
2. Navigate to: **Setup → Plugins**
3. Find the **Certificate Ticket** plugin
4. Click **Install** and then **Enable**

### ⚙️ Configuration

After installation, a new automatic task will be created:

1. Go to: **Setup → Automatic Actions**
2. Find the **certificateticket** task
3. Configure as needed (default: runs every 24 hours)

**Note**: Certificate alert settings are configured per entity at:
- **Setup → Entities → [Your Entity] → Alerts**
- Set "Send certificates alerts before delay" (default: 30 days)

### 📊 How It Works

1. **Daily Execution**: The automatic task checks certificates across all entities
2. **Verification**: Compares expiration date with configured alert period
3. **Ticket Creation**: If certificates are found near expiration:
   - Creates a ticket with formatted title
   - Includes complete certificate details
   - Automatically assigns responsible parties
   - Records in database to prevent duplicates
4. **Update**: If expiration date changes, creates new ticket

### 📝 Example Created Ticket

**Title**: 🔒 Digital Certificate Expiring - Certificate Name (Serial)

**Content**:
```
⚠️ ATTENTION: Action Required - Digital Certificate Renewal

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 CERTIFICATE DETAILS:
   • Name: example.com
   • Serial: ABC123456
   • Expiration Date: 03/15/2026

🔔 ACTION REQUIRED:
This digital certificate is approaching its expiration date...

✅ NEXT STEPS:
   1. Verify current certificate validity
   2. Start renewal process...
```

### 🗂️ Project Structure

```
certificateticket/
├── src/
│   ├── CertificateTicket.php   # Main class and ticket creation logic
│   └── Config.php              # Settings (reserved for future use)
├── hook.php                    # Installation/uninstallation and table creation
├── setup.php                   # Plugin setup and hooks
├── certificateticket.xml       # Metadata for GLPI marketplace
├── certificateticket.svg       # Plugin icon
└── README.md                   # This file
```

### 🔧 Future Development

- [ ] Custom configuration interface
- [ ] Option to assign default requester
- [ ] Ability to configure ticket template
- [ ] Multi-language support (i18n)
- [ ] Certificate reports and dashboard

### 🐛 Issues and Support

Found a bug or have a suggestion?

- [Open an issue](https://github.com/celsocaninde/CertificateTicket/issues)
- Contact through GitHub

### 📄 License

This project is licensed under **GPL v2+**. See the [LICENSE](LICENSE) file for details.

### 👨‍💻 Author

**ADZ** - Development and maintenance

### 🙏 Contributing

Contributions are welcome! Feel free to:
- Fork the project
- Create a feature branch (`git checkout -b feature/NewFeature`)
- Commit your changes (`git commit -m 'Add new feature'`)
- Push to the branch (`git push origin feature/NewFeature`)
- Open a Pull Request

---

**Made with ❤️ for the GLPI community**
