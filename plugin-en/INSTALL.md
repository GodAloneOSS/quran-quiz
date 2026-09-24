# Quranic Quiz — Install Guide

Bismillah. This guide gets the plugin live on **godalone.in** in about 10 minutes.

## 1. Install the plugin

1. Log in to your WordPress admin (`https://www.godalone.in/wp-admin/`).
2. Go to **Plugins → Add New → Upload Plugin**.
3. Choose the file `quranic-quiz.zip` and click **Install Now**, then **Activate**.
4. A new **Quranic Quiz** menu appears in the left sidebar. Open it — you should
   see **427 multiple-choice + 44 True/False questions (471 total)** already
   loaded on the **Questions** tab, imported automatically from your Excel file
   the first time the plugin activates.

## 2. Create your free Google Sign-In Client ID

Google Sign-In needs a **Client ID** registered to your domain. This is free
and only Google can issue it — I can't create it on your behalf.

1. Go to **https://console.cloud.google.com/** and sign in with any Google account.
2. If you don't already have a project, click the project dropdown (top left) →
   **New Project** → name it e.g. `GodAlone Quiz` → **Create**.
3. In the left menu go to **APIs & Services → OAuth consent screen**.
   - User type: **External** → **Create**.
   - App name: `Quranic Quiz` (or `GodAlone.in`). Support email: your email.
   - Add your email again under "Developer contact information".
   - Save and continue through Scopes (no changes needed) and Test users
     (skip) until you reach the summary, then **Back to Dashboard**.
   - If asked, click **Publish App** so any Google user (not just test users)
     can sign in.
4. In the left menu go to **APIs & Services → Credentials**.
   - Click **+ Create Credentials → OAuth client ID**.
   - Application type: **Web application**.
   - Name: `GodAlone Quranic Quiz`.
   - Under **Authorized JavaScript origins**, click **+ Add URI** and add:
     - `https://www.godalone.in`
     - `https://godalone.in` (without www, in case both resolve)
   - You do **not** need to add a redirect URI for this plugin.
   - Click **Create**. A box pops up with your **Client ID** — copy it
     (looks like `123456789-abc...xyz.apps.googleusercontent.com`).

## 3. Add the Client ID to the plugin

1. In WordPress admin, go to **Quranic Quiz → Settings**.
2. Paste the Client ID into **Google OAuth Client ID** and click **Save settings**.
3. (Optional) Adjust questions-per-quiz, the timer, site name and certificate title here too.

## 4. Add the quiz to your page

1. Edit your **/quran-qa/** page (or create it: **Pages → Add New**, slug `quran-qa`).
2. Add a **Shortcode** block (or Custom HTML block) containing:

   ```
   [quranic_quiz]
   ```

3. Publish/update the page. Visit `https://www.godalone.in/quran-qa/` — you
   should see the Sign in with Google screen.

## 5. Try it end-to-end

1. Sign in with a Google account.
2. Pick a difficulty, answer a few questions, let the timer run or submit early.
3. Confirm the score screen, certificate download (PDF), and quiz history all work.
4. Back in wp-admin → **Quranic Quiz → Dashboard**, confirm the attempt shows up.

## Adding or fixing questions later

Go to **Quranic Quiz → Import Questions** any time to:
- Add more questions from a new CSV (keeps existing ones), or
- Replace the entire question bank with a fresh CSV.

Download the **CSV template** button on that page for the exact column headers.
If your new questions are in Excel, just "Save As → CSV" from Excel/Google
Sheets first, keeping the same column headers.

Rows with a missing option, an unrecognised difficulty, or a correct answer
that doesn't match a real option are **skipped and listed on screen** —
nothing is ever guessed or invented.

## Notes on data & privacy

- Each visitor's quiz history is private to them — the REST API only ever
  returns a user's own attempts, never another user's.
- Quiz sessions are separate from native WordPress accounts, so any visitor
  with a Google account can take the quiz, whether or not they have a
  WordPress login on your site.
- Uninstalling the plugin normally **keeps** all questions and quiz history
  in the database (in case you reinstall). To fully wipe everything, see the
  comment at the top of `uninstall.php`.

May Allah accept this effort and make it a means of benefit. Ameen.
