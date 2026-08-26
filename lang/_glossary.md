# i18n Glossary

Lock terminology so future translations stay consistent. When a new
key lands in `lang/{locale}.json`, look up its core terms here first
to avoid drift (e.g. one place using "espacio de trabajo", another
using "área de trabajo" for the same concept).

## Core nouns

| English          | es (Spanish)         | fr (French)         | tr (Turkish)         |
|------------------|----------------------|---------------------|----------------------|
| Workspace        | Espacio de trabajo   | Espace de travail   | Çalışma alanı        |
| Agent            | Agente               | Agent               | Ajan                 |
| Conversation     | Conversación         | Conversation        | Konuşma              |
| Visitor          | Visitante            | Visiteur            | Ziyaretçi            |
| Lead             | Contacto             | Prospect            | Müşteri adayı        |
| Message          | Mensaje              | Message             | Mesaj                |
| Source           | Fuente               | Source              | Kaynak               |
| Knowledge base   | Base de conocimiento | Base de connaissances | Bilgi tabanı       |
| Operator         | Operador             | Opérateur           | Operatör             |
| Owner            | Propietario          | Propriétaire        | Sahip                |
| Member           | Miembro              | Membre              | Üye                  |
| Invitation       | Invitación           | Invitation          | Davet                |
| Subscription     | Suscripción          | Abonnement          | Abonelik             |
| Plan             | Plan                 | Forfait             | Plan                 |
| Tag              | Etiqueta             | Étiquette           | Etiket               |
| Note             | Nota                 | Note                | Not                  |
| Live chat        | Chat en vivo         | Chat en direct      | Canlı sohbet         |
| Handoff          | Transferencia        | Transfert           | Aktarım              |
| Dashboard        | Panel                | Tableau de bord     | Panel                |

## Verbs (keep imperative form)

| English          | es                   | fr                  | tr                   |
|------------------|----------------------|---------------------|----------------------|
| Save             | Guardar              | Enregistrer         | Kaydet               |
| Cancel           | Cancelar             | Annuler             | İptal                |
| Delete           | Eliminar             | Supprimer           | Sil                  |
| Edit             | Editar               | Modifier            | Düzenle              |
| Create           | Crear                | Créer               | Oluştur              |
| Add              | Añadir               | Ajouter             | Ekle                 |
| Send             | Enviar               | Envoyer             | Gönder               |
| Connect          | Conectar             | Connecter           | Bağlan               |
| Claim            | Tomar                | Prendre en charge   | Üstlen               |
| Transfer         | Transferir           | Transférer          | Aktar                |
| Sign in          | Iniciar sesión       | Se connecter        | Giriş yap            |
| Sign up          | Registrarse          | S'inscrire          | Kayıt ol             |
| Log out          | Cerrar sesión        | Se déconnecter      | Çıkış yap            |

## Tone

- Match Pitchbar's product voice: direct, friendly, second-person
  (`tú` in Spanish, `tu`/`vous` in French — prefer informal `tu` to
  match the marketing's consumer feel; switch to `vous` only in
  legal/contract contexts), `siz` in Turkish.
- Avoid English borrowings when a clean local equivalent exists
  ("widget" → keep, but "dashboard" becomes `panel` in Spanish).
- Keep punctuation consistent with the locale (Spanish: `¿…?`,
  `¡…!`; French: thin non-breaking space before `:` `;` `?` `!`;
  Turkish: standard).

## Placeholders

`:placeholder` and `{placeholder}` are runtime-substituted by both
the admin SPA (`useT`) and the widget (`t`) helpers. Never translate
the placeholder name itself — only the surrounding text.

  `Showing :from–:to of :total`
  → es: `Mostrando :from–:to de :total`
  → fr: `Affichage de :from–:to sur :total`
  → tr: `:total içinden :from–:to gösteriliyor`

## Adding a new locale

1. Add the slug to `LocaleResolver::SUPPORTED` and to
   `LOCALE_LABELS` / `LOCALE_FLAGS` in `resources/js/lib/i18n.ts`.
2. Copy `lang/en.json` → `lang/<slug>.json` and translate.
3. Copy `lang/en/{auth,validation,passwords,pagination}.php`
   into `lang/<slug>/`.
4. Run the test suite — `MarketingI18nTest` and `I18nTest` will
   fail loudly if the new locale is missing required keys.
5. Add a row to every table above so future translations stay
   consistent with this run.

## Untranslated surfaces (intentional — out of card scope)

The following surfaces still render in English regardless of locale.
Track follow-up work in the Kanban board, not by editing this list:

- Documentation pages (`resources/views/documentation/pages/*.blade.php`).
  41 dense technical writeups; full translation is a copywriting
  project, not engineering. The chrome (nav, language switcher) is
  translated; page bodies stay English.
- Deep admin pages (Agents customize / sources / curated / playground;
  Conversations show; Settings widget). The `useT` helper is wired in
  the layout shell — sweeps are mechanical follow-ups.
- Onboarding wizard step copy.
- Some platform-admin pages under `/admin/*`.
