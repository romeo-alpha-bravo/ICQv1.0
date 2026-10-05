# User guide

How to use ICQ1.0 as a regular user and as an administrator.

## Your UIN

Every account gets a **UIN** (Universal Internet Number), a personal number such as
`65841753`. It is shown in your profile and in the top right corner of the window. Other
people use it to write to you, and you use theirs to write to them. The UIN never changes.

## Creating an account

Open the site and choose **Register**. All fields are required.

| Field | Rules |
|---|---|
| First name, Last name | letters, spaces, apostrophe and hyphen, up to 100 characters |
| Email | a valid address; each address can be registered only once |
| Phone | 9 to 15 digits, optionally starting with `+`; spaces and hyphens are allowed |
| Gender | Male, Female or Other |
| Profile photo | see below |
| Login | 3 to 30 characters: English letters, digits, underscore; must be unique |
| Password | 8 to 128 characters, typed twice |

### Profile photo rules

- Formats: JPEG, PNG, GIF, BMP or TIFF (TIFF only when the server supports it).
- At least **800 pixels wide**; smaller pictures are rejected rather than blown up.
- At most 10 MB, at most 10,000 px on a side and 20 megapixels.
- The photo is stored as a JPEG 800 px wide with the height in proportion. Transparent areas
  become white, animated GIFs keep only the first frame, and phone photos are turned the right
  way up. Location data and other hidden metadata are removed.

Most mistakes are reported as you type. The server checks everything again; if a field is
still wrong, the form comes back with your entries and a note under the field. The password
and the photo have to be entered again: passwords are never sent back to the browser, and
browsers cannot refill a file field.

After a successful registration you are logged in straight away.

## Logging in and out

Enter your login and password on the **Log in** screen.

- After **20 minutes without activity** you are logged out automatically. The login screen
  then says why. Reading the inbox in the background does not count as activity.
- After **10 wrong passwords within 15 minutes** for one login or from one network address,
  logging in is blocked for 15 minutes.
- **Log out** is the button at the right end of the menu.

### Forgot your password?

1. On the login screen choose **Forgot your password?** and enter your e-mail address.
2. The page always says that a link was sent if the account exists. It does not reveal
   whether the address is registered.
3. Open the link from the e-mail within **30 minutes** and type the new password twice.
4. Log in with the new password. The link works only once; requesting a new link cancels
   older ones.

At most 3 links per account per hour and 5 requests per network address per 15 minutes are
accepted.

## The main window

The menu at the top is the same on every page:

| Item | What it does |
|---|---|
| **Inbox** | messages you received; the red number counts unread ones |
| **Sent** | messages you sent and whether they have been read |
| **Write** | a new message |
| **Contacts** | everyone else, split into Online and Offline |
| **Profile** | your data and settings |
| **Users** | user management, shown to administrators only |
| **Log out** | ends the session |

## Messages

### Reading

**Inbox** lists messages newest first with the sender's photo, name, UIN, subject and time.
Unread messages are bold. Open one by clicking its subject; it is then marked as read. Use
**Reply** under the message to answer: the recipient and the subject (`Re: …`) are filled in.

Folders show the newest 200 messages.

### Writing

1. Choose **Write**, or **Write** next to a person in **Contacts**.
2. **To (UIN):** start typing a number or a name; suggestions show matching users.
3. **Subject:** one line, up to 200 characters.
4. **Message:** up to 10,000 characters, line breaks are kept.
5. **Send** takes you to **Sent**, where the new message appears.

You cannot write to yourself. In **Sent**, the status changes from *not read* to *read* when
the recipient opens the message.

### Notifications

While any page is open, the site checks for new mail every 20 seconds. When something
arrives:

- the red number next to **Inbox** goes up;
- the browser tab shows the count, for example `(2) ICQ1.0 - Inbox`;
- a short sound plays. Browsers allow sound only after you have clicked somewhere on the page
  at least once.

## Contacts

**Contacts** lists all other users with photo, name and UIN. People who used the site in the
last 5 minutes are in the **Online** group with a green marker, everybody else is in
**Offline**. **Write** next to a name opens a message with the recipient filled in.

## Your profile

**Profile** shows your UIN, login, role, name, e-mail, phone, gender, registration date and
photo. Below it you can change:

- first and last name, e-mail, phone and gender;
- the photo (optional; the same rules as at registration, the old photo is deleted);
- the password: type the **current password** and the new one. The current password is only
  needed when you change the password.

Your login and your role cannot be changed here. Regular users can edit only their own data.

## For administrators

Administrators see the extra menu item **Users**.

### The user list

A table of all accounts with UIN, login, name, role, online status and registration date.

- **Make admin** / **Revoke admin** switches the role of that user immediately; the change
  applies to their next page view.
- You cannot change your own role, so you cannot lock yourself out.
- The last remaining administrator cannot lose the role.

### Editing a user

**Edit** opens a form with all of the user's data:

- first and last name, e-mail, phone, gender and photo, with the same rules as everywhere;
- the role (locked when you edit yourself);
- **Set a new password**: an administrator can set a password without knowing the old one,
  for example for a user who has no access to their e-mail. Leave it empty to keep the
  current password.

### The default administrator

A fresh installation has one administrator with the login `admin` and UIN `10000`. It is
activated once by whoever installs the system (see [INSTALL.md](INSTALL.md), step 4).

## Messages you might see

| Message | Meaning |
|---|---|
| Invalid login or password. | wrong login or password (the screen does not say which) |
| Too many failed attempts. Please try again in 15 minutes. | login is temporarily blocked |
| You were logged out after 20 minutes of inactivity. | the session expired; log in again |
| This login is already taken. / This email is already registered. | choose a different one |
| The photo must be at least 800 px wide. | choose a larger picture |
| There is no user with this UIN. | check the recipient's number |
| This link is invalid or has expired. | request a new reset link |
| Access denied. | the page is for administrators, or the form expired; reload the page |
| Something went wrong. Please try again later. | a server problem; the details are in the server log |
