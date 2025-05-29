# Changelog

## 2025-05-26

### Novidades e melhorias deste fork

- **Nome personalizado para grupos:** Agora é possível definir um nome customizado (`customgroupname`) para os grupos criados automaticamente por curso. Esse nome é salvo no banco, exibido corretamente na interface e atualizado inclusive para grupos já criados.
- **Fluxo de criação, edição e exclusão aprimorado:** Após criar, editar ou excluir um conjunto de grupos, o usuário é redirecionado para a página de gerenciamento com uma mensagem de sucesso, melhorando a experiência de uso.
- **Atribuição automática de alunos:** O plugin agora inscreve automaticamente os usuários nos grupos de acordo com o valor personalizado do campo de perfil (profile field) definido na configuração. Se o valor do campo do usuário corresponder ao valor do grupo, ele é inscrito automaticamente. Caso contrário, ele não é inscrito ou é removido do grupo.
- **Atualização de nomes de grupos:** Caso o nome personalizado seja alterado numa edição, todos os grupos relacionados têm o nome atualizado no banco de dados e na interface do Moodle.
- **Formulário robusto e intuitivo:** Todos os campos do formulário (incluindo filtro e nome personalizado) são exibidos e preenchidos corretamente, garantindo clareza e evitando erros de configuração.
- **Tratamento aprimorado de navegação:** Parâmetros de navegação tratados de forma a evitar inconsistências e garantir o correto funcionamento dos fluxos de criação, edição e exclusão.
- **Atualização automática da lista de membros:** Sempre que um usuário é adicionado ou removido de um grupo, a lista de membros é atualizada para refletir a mudança imediatamente.
- **Compatibilidade com Moodle 4.x:** Diversos ajustes para garantir funcionamento pleno nas versões recentes do Moodle.
- **Correções de bugs e pequenos aprimoramentos internos** para estabilidade e manutenção do código.

---

### ⚠️ Atenção

- O campo **Custom profile field** ainda precisa de mais testes e pode demandar ajustes adicionais para pleno funcionamento.

---

### English summary

This fork includes:
- Support for custom group names at the course level (`customgroupname`), fully reflected in the database and UI.
- Automatic enrollment of users into groups according to the configured profile field value. Users are enrolled if their profile value matches the group, and automatically removed if it does not.
- Improved handling and display of personalized group names in all interfaces, including updates when the name is changed.
- Enhanced user experience: after saving or editing a group set, users are redirected to the management page with a confirmation message.
- Robust handling of user enrollments and group membership updates, with immediate reflection of changes.
- Form now displays and fills all relevant fields, including custom name and filter, in a clear and error-free manner.
- Navigation parameters are handled robustly, ensuring smooth creation, editing, and deletion workflows.
- Additional bug fixes and optimizations for Moodle 4.x compatibility.

---

### ⚠️ Notice

- The **Custom profile field** option still requires further testing and adjustments.

---
