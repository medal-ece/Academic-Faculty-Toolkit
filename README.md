# Academic Faculty Toolkit

Academic Faculty Toolkit is a WordPress plugin for building and maintaining a faculty or research group people page. It renders a PI section, grouped student/member cards, and automatic profile pages from database-backed people data.

It also adds SEO/social metadata and Person/Organization schema for generated Research Group, PI, and student profile routes, plus a last-saved indicator in the Toolkit dashboard.

## First-Time Setup With Faculty Theme

If you are setting up the MEDAL website from a fresh WordPress install, use both admin menus:

- **Faculty Theme** controls the website appearance, homepage, page headers, gallery, contact page, research page, footer, logos, and visual settings.
- **Faculty Toolkit** controls the PI, students/members, education records, profile links, CSV exports/backups, and automatic research-group routes.

Recommended first steps:

1. Activate **Faculty Theme** under **Appearance > Themes**.
2. Activate **Academic Faculty Toolkit** under **Plugins**.
3. Go to **Settings > Permalinks** and click **Save Changes** once.
4. Create these WordPress pages:
   - `Home`
   - `NEWS`
   - `Research` using the **Faculty Research** template
   - `Courses`
   - `Gallery` using the **Faculty Gallery** template
   - `Contact` using the **Faculty Contact** template
5. Go to **Settings > Reading**:
   - Set **Your homepage displays** to `A static page`.
   - Set **Homepage** to `Home`.
   - Set **Posts page** to `NEWS`.
6. Go to **Appearance > Menus** and create the main navigation:
   - Home
   - NEWS
   - Research
   - Research Group
   - Courses
   - Gallery
   - Contact
7. Add **Research Group** as a custom menu link:

```text
/research-group/
```

Do not create a normal WordPress page at `/research-group/`. This plugin automatically owns that route.

After the pages and menu are ready:

- Use **Faculty Theme > General / Front Page / MEDAL Intro / Slider / News / Contact / Research / Gallery / Footer** to initialize the visible website.
- Use **Faculty Toolkit > Toolkit Dashboard** for PI Information, Students, Profile Links, Email Settings, Settings, Backups, and Site Health.

The main shortcode is:

```text
[student_list]
```

## What It Provides

- A public research group directory page.
- A Principal Investigator section at the top of the directory.
- Student/member cards grouped by category and current/past status.
- Automatic profile pages at:

```text
/research-group/{profile-slug}/
```

- Automatic PI profile page at:

```text
/research-group/PI/
```

- A WordPress admin panel under **Faculty Toolkit > Toolkit Dashboard**.
- Admin editing for PI information, students, student images, and education records.
- Database-backed PI/student/education records with CSV export plus automatic CSV backups with restore/delete controls.
- Private token-authenticated profile editing without student WordPress accounts.
- File-backed profile-link hashes and email settings under `data/private/`.

## Installable Releases and Updates

Create an installable plugin ZIP from this plugin directory with:

```powershell
.\build-release.ps1
```

This creates `releases/academic-student-directory.zip`.

When working from the current XAMPP `wp-content` workspace, you can also run:

```powershell
.\plugins\academic-student-directory\build-release.ps1
```

The plugin can be updated later through **Plugins > Add New > Upload Plugin**. WordPress will replace the plugin code, while PI/student/member data remains in the WordPress database.

Optional automatic updates are supported if a release JSON endpoint is configured in `wp-config.php`:

```php
define('ACADEMIC_DIRECTORY_UPDATE_JSON', 'https://raw.githubusercontent.com/medal-ece/Academic-Faculty-Toolkit/main/update-manifest.json');
```

The JSON should include at least:

```json
{
  "version": "4.0.1",
  "download_url": "https://github.com/medal-ece/Academic-Faculty-Toolkit/releases/download/v4.0.1/academic-student-directory.zip",
  "details_url": "https://github.com/medal-ece/Academic-Faculty-Toolkit/releases/tag/v4.0.1"
}
```

For source control, connect this directory to:

```text
https://github.com/medal-ece/Academic-Faculty-Toolkit
```

## Recommended Workflow

The easiest maintenance workflow is:

1. Add students and their administrator-managed information under **Faculty Toolkit -> Toolkit Dashboard -> Students**.
2. Generate private edit links under **Faculty Toolkit -> Toolkit Dashboard -> Profile Links**.
3. Copy or email each student their link.
4. Let students maintain their approved profile and education fields.
5. Manage images, PI information, categories, pronouns, and education-title options from the WordPress admin screens.
6. Use **Faculty Toolkit -> Toolkit Dashboard -> Settings** for CSV export, automatic backup restore, and backup cleanup/deletion.

The plugin uses the WordPress database as the live source of truth. CSV files are retained as seed/import/export/backup formats, not as the primary live storage layer.

Clean release ZIPs created by `build-releases.ps1` intentionally exclude bundled `data/*.csv` files, so a fresh installation starts with empty PI/student/member data.

## Private Student Profile Editing

Administrators can create private student edit links from **Faculty Toolkit -> Toolkit Dashboard -> Profile Links**.

Each generated link:

- Uses a cryptographically random 64-character token.
- Stores only its SHA-256 hash in `data/private/student-edit-tokens.csv`.
- Is associated with one student profile slug.
- Expires after the configured number of days.
- Replaces and revokes the student's previous active link.
- Can be revoked manually.

Students open `/edit-profile/?token=...` and can edit only approved public fields: name, secondary email, website, pronouns, biography, research interests, hobbies, current position, and education. Primary email, category, active/past status, profile slug, image, date of entry, and ordering remain administrator-controlled.

The primary email is private: it is used by administrators for research group records and private-link delivery, and it is not displayed on public cards or profiles. The optional `Secondary Email` field is the student's public email. Students may enter the same address as their primary email when they want that address displayed publicly. Public email links use crawler-resistant protected-link behavior.

The edit form displays administrator-controlled values as read-only context. Students can add or remove any number of education records with the education controls. Semicolon-separated research interests are displayed as separate list items on profile pages.

Before a student submission or admin save replaces database-backed profile data, the plugin creates rolling CSV backups in the protected `data/backups/` folder. Token pages receive no-cache, no-referrer, and no-index headers.

### Email Settings

Configure invitation messages from **Faculty Toolkit -> Toolkit Dashboard -> Email Settings**. The sender defaults to `admin@your-site-domain`.

Available placeholders:

- `{student_name}`
- `{edit_link}`
- `{site_name}`
- `{expires_at}`

The **Generate / Email** action sends through WordPress `wp_mail()`. WordPress accepting a message does not guarantee final delivery; SMTP and deliverability remain part of the host configuration. If WordPress cannot send, the plugin displays the generated link for manual copying.

## Research Group Route

The main directory is generated automatically at:

```text
/research-group/
```

You do not need to create a WordPress page for the Research Group directory. If an old WordPress page exists at that slug, delete it or move it to another slug so the plugin route is the only owner of `/research-group/`.

The legacy shortcode is still available for unusual cases:

```text
[student_list]
```

The plugin will render:

1. PI section.
2. Current researchers.
3. Past members.
4. Member categories in the order managed under **Faculty Toolkit > Settings**.

## Student Profile URLs

Each student profile is generated automatically from the student's profile slug:

```text
/research-group/soroosh-noorzad/
```

You do not need to create a separate WordPress page for every student.

## PI Profile URL

The PI profile page is generated automatically from PI information:

```text
/research-group/PI/
```

The research group PI section includes a Profile link to this page.

If profile URLs return 404 after changing the plugin, go to:

```text
WordPress Admin -> Settings -> Permalinks -> Save Changes
```

That refreshes WordPress rewrite rules.

## Admin Panel

Open:

```text
WordPress Admin -> Faculty Toolkit
```

### PI Information

Use this tab to edit the PI section shown at the top of the directory.

The PI data is stored in:

```text
data/principal-investigator.csv
```

The PI tab includes:

- Name, title, department, and institution.
- Contact information.
- Website, LinkedIn, GitHub, Google Scholar, ORCID, ResearchGate, and CV links.
- Drag-and-drop PI link display order for the PI profile and Research Group PI card.
- WordPress Media Library image selection.
- Short bio for the research group page.
- Rich text editor for the full PI biography page.
- Rich text editors for education, professional experience, and honors/awards sections.
- Useful section title/content for a PI-page resources area.
- Research interests, separated with semicolons.

### Open Positions

Use this tab to manage the recruiting/vacancies callout shown below the PI card on the Research Group page.

The Open Positions tab includes:

- show/hide checkbox
- small label
- title
- rich text main body
- button label and URL, commonly pointed to a FAQ post

### Students

Use this tab to add or edit students/members.

Each student has:

- Profile information.
- Contact information.
- Academic information.
- Personal information.
- Image selected from the WordPress Media Library.
- Education records.

Images are intentionally managed here and are not included in CSV exports.

### Settings

Use this tab to:

- Download separated Students and Education CSV exports.
- Restore or delete automatic CSV backups.
- Edit student categories and their display order.
- Edit pronoun dropdown options.
- Edit education title dropdown options and their order.
- View important plugin paths and shortcode information.

## CSV Export Structure

The live plugin storage remains CSV files in the `data/` directory. Admin downloads are export-only; the plugin does not accept CSV uploads.

### Students CSV

The exported Students CSV file uses these columns:

```text
Profile Slug,Category,Active,Name,Email,Secondary Email,Website,Bio,Date of Entry,Pronoun,Research Interests,Hobbies,Current Position,Position Updated
```

Notes:

- `Profile Slug` is the stable identifier for each student.
- `Profile Slug` controls the profile URL.
- Example: `soroosh-noorzad` becomes `/research-group/soroosh-noorzad/`.
- `Active` controls whether the person appears under Current Researchers or Past Members.
- Use `y` for current members and `n` for past members.
- `Current Position` and `Position Updated` are mainly for past members. When available, the current position appears on past-member cards and on the member profile page.
- The image column is not included. Images stay controlled by the WordPress admin panel.

### Education CSV

The exported Education CSV file uses these columns:

```text
Profile Slug,Education Title,Institution,University Link,Start Date,End Date
```

PI information and generic options are edited directly in the admin panel instead of being exported for student editing.

Notes:

- A student can have multiple education rows.
- `Profile Slug` connects each education row to the matching student.
- Leave `End Date` empty, or use `Present`, for ongoing education.
- `University Link` is optional.
- Profile pages show education from highest/latest degree first, such as Ph.D., then M.Sc., then B.Sc.

## Data Files

Plugin-local CSV files live in:

```text
data/
```

Important files:

```text
data/students.csv
data/student-education.csv
data/principal-investigator.csv
data/student-category-order.csv
data/pronouns.csv
data/education-title-order.csv
data/index.php
data/private/index.php
data/private/student-edit-tokens.csv
data/private/email-settings.csv
```

### Category Order

Categories and their display order are stored in:

```text
data/student-category-order.csv
```

Example:

```csv
Category,Rank
Post Doc.,1
Ph.D.,2
M.Sc.,3
B.Sc.,4
Visiting Student,5
High School,6
Student,7
```

Lower rank numbers appear first.

### Pronouns

Pronoun options for the student editor are stored in:

```text
data/pronouns.csv
```

### Education Titles

Education title options are stored in:

```text
data/education-title-order.csv
```

Expected columns:

```csv
Title,Rank
Ph.D.,1
M.Sc.,2
```

## Templates

Public templates live in:

```text
templates/
```

Key templates:

```text
templates/student-directory-page.php
templates/student-list.php
templates/virtual-profile-page.php
templates/student-profile-content.php
templates/student-edit-page.php
templates/student-edit-form.php
templates/pi-profile-content.php
templates/partials/pi-section.php
templates/partials/student-card.php
includes/class-academic-profile-access.php
```

The profile page content is injected into the active WordPress theme page layout, so profile pages should inherit the site's normal header, footer, and theme styling.

Virtual directory/profile routes use `templates/virtual-profile-page.php`. This wrapper calls the active theme header/footer but does not call the theme sidebar, so `/research-group/`, `/research-group/PI/`, and `/research-group/{profile-slug}/` stay full-width even when the theme's default page template has a sidebar. The optional shared hero image and title size are controlled from **Faculty Toolkit -> Settings -> Virtual Profile Pages**. Hero titles are generated from context: `Research Group` for the directory route, `Principal Investigator` for the PI route, and a researcher title based on the student's category for student routes.

`templates/student-list.php` is kept as a backward-compatible alias for older shortcode settings. New usage should rely on `templates/student-directory-page.php`.

## Styling

Public CSS lives in:

```text
assets/css/student-directory.css
```

This controls the PI section, student cards, category sections, and profile content.

Admin-only assets live in:

```text
assets/css/admin.css
assets/js/admin.js
```

## Common Tasks

### Add a Student

1. Go to **Faculty Toolkit -> Students**.
2. Click **Add Student**.
3. Fill the profile fields.
4. Select or upload an image from the Media Library.
5. Add education records if needed.
6. Save.

### Invite a Student to Update Their Profile

1. Go to **Faculty Toolkit -> Profile Links**.
2. Choose **Generate / Copy** or **Generate / Email** for the student.
3. Send the private link if it was copied manually.
4. Revoke or regenerate the link when needed.

### Export Directory Data

Go to **Faculty Toolkit -> Settings -> CSV Export** and download the Students or Education CSV file. These files are intended for backup, reporting, or offline reference and cannot be uploaded back into the plugin.

### Change Category Order

Go to **Faculty Toolkit -> Settings -> Generic Options** and edit **Student Categories**.

The rank controls the public display order. Lower numbers appear first.

### Add Pronoun Options

Go to **Faculty Toolkit -> Settings -> Generic Options** and edit **Pronouns**.

The options will appear in the student editor.

### Add Education Title Options

Go to **Faculty Toolkit -> Settings -> Generic Options** and edit **Education Titles**.

These options appear in the education editor for each student.

## Notes

- Do not manually edit `Student ID` unless you know you need to change a profile URL.
- CSV exports intentionally exclude images.
- If a student slug changes, the old profile URL will stop working unless redirects are added separately.
