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

After logging in you land in the **Messenger**. The bar at the top is the same on every page:

| Item | What it does |
|---|---|
| **Messenger** | chats with your contacts; the yellow number counts unread chat messages |
| **Mail** | letters: Inbox, Sent, Write a letter; the number counts unread letters |
| **Profile** | your data and settings |
| **Users** | user management, shown to administrators only |
| your photo | your current status; opens your profile |
| **Log out** | ends the session and shows you as offline right away |

## Messenger

### Statuses

The button at the top of the contact list shows your status. Click it to choose:

| Status | Marker | Meaning |
|---|---|---|
| Online | green dot | you are here |
| Away | yellow moon | you stepped away; set **automatically** after 10 minutes without activity |
| Not available | orange dot with a white centre | you are not at the computer for a longer time |
| Do not disturb | red bar | no sounds and no desktop notifications for you |
| Invisible | grey ring with a dot | you can chat, but everyone sees you as offline and your "last seen" is hidden |
| Offline | grey ring | no open ICQ1.0 tab for about 90 seconds, or logged out |

Each marker has its own shape, so statuses are recognizable without colors too. Under the
statuses you can type a **status text** ("At a lecture"); contacts see it next to your name.

### Adding people (authorization)

1. Click the person icon next to your status and search by **UIN**, login or name.
2. Click **Add**. The person gets an **authorization request**.
3. Once they **accept**, you appear in each other's list. Until then you are listed under
   *Waiting for authorization* and can cancel the request.

Only accepted contacts can chat with each other and see each other's status. If you both send
a request, you are connected at once. Requests sent to you appear at the top of the list with
**Accept**, **Decline** and **Info**.

### The contact list

- Contacts are grouped (**General** at first). Click a group name to fold it; the numbers show
  how many are online out of the whole group.
- Online contacts are listed first, offline ones are greyed.
- A yellow number next to a contact counts their unread messages.
- **Filter contacts** narrows the list by name or UIN.
- To move a contact into another group, open their **info card** (ⓘ in the chat header) and
  choose **Move to group**. Type an existing group or a new name.

### Chatting

Click a contact to open the conversation.

- **Enter** sends, **Shift+Enter** starts a new line. Up to 2,000 characters.
- The smiley button opens a panel of emoji; a message of emoji only is shown large.
- While the other person types, you see **typing…** under their name and three dots in the chat.
- Your messages carry ticks: **✓** sent, **✓✓** delivered (their app received it),
  **light blue ✓✓** read (they had the chat open).
- **Show earlier messages** at the top loads older history.
- The envelope button writes a **letter** to the same person instead.

### Info card

ⓘ in the chat header (or **Info** on a request) shows photo, name, UIN, city and "about me".
Contacts also see your status, status text and, while you are offline, when you were last
seen. From the card you can write a message or a letter, move the contact to a group or
**remove** them; removing works on both sides, and a new request is needed to chat again.

### Notifications

When a message arrives in a chat you are not looking at:

- a short sound plays (browsers allow sound only after you have clicked on the page once);
- the tab title blinks while the tab is in the background, for example `✉ Bob writes…`;
- a **desktop notification** appears if you allowed it with **Turn on desktop notifications**
  in the contact list. Clicking it opens the conversation.

In **Do not disturb** there are no sounds and no desktop notifications, only the counters.

## Mail

### Reading

**Inbox** lists messages newest first with the sender's photo, name, UIN, subject and time.
Unread messages are bold. Open one by clicking its subject; it is then marked as read. Use
**Reply** under the message to answer: the recipient and the subject (`Re: …`) are filled in.

Folders show the newest 200 messages.

### Writing

1. Choose **Write a letter**, or the envelope button in a chat.
2. **To (UIN):** start typing a number or a name; suggestions show matching users.
3. **Subject:** one line, up to 200 characters.
4. **Message:** up to 10,000 characters, line breaks are kept.
5. **Send** takes you to **Sent**, where the new message appears.

You cannot write to yourself. In **Sent**, the status changes from *not read* to *read* when
the recipient opens the message.

### Notifications

On pages other than the messenger, the site checks every 20 seconds. When something arrives:

- the number next to **Mail** or **Messenger** goes up;
- the browser tab shows the count, for example `(2) ICQ1.0 - Inbox`;
- a short sound plays. Browsers allow sound only after you have clicked somewhere on the page
  at least once.

## Your profile

**Profile** shows your UIN, login, role, name, e-mail, phone, gender, registration date and
photo. Below it you can change:

- first and last name, e-mail, phone and gender;
- **city** and **about me** (optional), shown on your info card to other users;
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
| You can only write to people in your contact list. | send them a request and wait until they accept |
| This link is invalid or has expired. | request a new reset link |
| Access denied. | the page is for administrators, or the form expired; reload the page |
| Something went wrong. Please try again later. | a server problem; the details are in the server log |
