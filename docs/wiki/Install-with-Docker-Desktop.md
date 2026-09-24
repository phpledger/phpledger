# Install with Docker Desktop on Windows

**You will finish with PHP Ledger at [http://127.0.0.1:18080](http://127.0.0.1:18080), on your own computer.** Keep Docker Desktop running whenever you use it.

Allow time for the first download. You need Docker Desktop and an internet connection. PHP and the database are included in this setup.

Already pulled the PHP Ledger image from Docker Hub? You can still follow these steps. That download contains the application; the setup file below connects it to the database and saves your records between restarts.

## 1. Open Docker Desktop

If it is not installed, use the [official Windows download and installation steps](https://docs.docker.com/desktop/setup/install/windows-install/). Follow any request to enable WSL or restart Windows, then open Docker Desktop from Start. Use Linux containers if asked.

**Ready to continue:** Docker Desktop says its engine is running. You do not need to search its Images screen or click **Run** on an individual image.

## 2. Save the setup file

Create a new, empty folder called **PHP Ledger** in Documents.

Open [the setup file](https://github.com/phpledger/phpledger/blob/master/compose.desktop.yaml), then click **Download raw file** near the top of the file. Save it in your new folder as **compose.desktop.yaml**. If your browser saves it in Downloads, move it into the PHP Ledger folder.

This recipe installs release **1.4.1**. Its version stays fixed when you restart. It downloads PHP Ledger from GitHub Container Registry and MySQL from Docker Hub automatically.

**Ready to continue:** the folder contains `compose.desktop.yaml`. In File Explorer, turn on **View → Show → File name extensions** if needed; the name must not end in `.txt`.

## 3. Start PHP Ledger

Open that folder in File Explorer. Click the address bar, type `powershell`, and press Enter. A command window opens in the right folder.

Copy this whole block into the window and press Enter. It creates private database passwords in a file called `.env` and starts PHP Ledger. You do not need to edit the block.

```powershell
if (!(Test-Path .env)) {
    "PL_DB_PASSWORD=$([guid]::NewGuid().ToString('N'))" | Set-Content .env
    "PL_DB_ROOT_PASSWORD=$([guid]::NewGuid().ToString('N'))" | Add-Content .env
}
docker compose -f compose.desktop.yaml up -d --wait
```

The first run downloads two images and prepares the database. Wait until the command finishes successfully; later starts are faster. If it reports an error, use the help table below before continuing.

**Ready to continue:** Docker Desktop's **Containers** page shows a group named **phpledger-desktop** with `web` and `db` running.

## 4. Finish in your browser

Open [PHP Ledger](http://127.0.0.1:18080) and choose **Start setup**. A warning about HTTP is expected for this computer-only address. At **Database**, use these values:

| Box on the screen | What to enter |
|---|---|
| This site's address | `http://127.0.0.1:18080` |
| Database host | `db` |
| Port | `3306` |
| Table prefix | Keep `pl_` |
| TLS CA certificate path | Leave empty |
| Database name | `phpledger` |
| Database user | `phpledger` |
| Database password | In File Explorer, right-click `.env` → **Open with → Notepad**. Copy only the characters after `PL_DB_PASSWORD=` |

Keep `.env` private. This database password connects the application to its storage. You choose your own sign-in password at the **Account** step.

Click **Check database**. You should see **Database connected.** Then:

1. Click **Install database**. Keep the tab open while setup runs; click **Continue installation** if it asks.
2. At **Database checks**, click **Save private configuration**.
3. At **Create your sign-in account**, enter your name, username, email and a password of at least 12 characters. Enter the password again to confirm it. A logo is optional.
4. The optional **Tell phpledger.com this copy was installed** box may appear selected. Uncheck it if you do not want it selected. The desktop recipe prevents that notice from being sent; leave the separate name-and-email registration box off unless you want to register.
5. Click **Finish and create your business**. The page should say **Your installation is complete.** Click **Set up your first business** when you are ready to enter your business details.

**Finished:** you are signed in with your new account and can open business setup. Choose **New business** if you are starting from scratch, or **Bring past records** if you already have books. Business setup can be finished later.

## Open it again tomorrow

Open Docker Desktop. In **Containers**, start the **phpledger-desktop** group if it is stopped. Then open [PHP Ledger](http://127.0.0.1:18080) using a browser bookmark. Use the group's **Stop** button when finished; your records remain saved. Keep the PHP Ledger folder and its `.env` file.

## If you get stuck

| What you see | What to do |
|---|---|
| “Docker is not recognized” or “cannot connect to the Docker engine” | Open Docker Desktop and wait for it to finish starting, then reopen PowerShell from your PHP Ledger folder. If it asks for WSL or a restart, complete that first. |
| “No such file” or “compose.desktop.yaml not found” | Check the folder and filename from step 2, then reopen PowerShell from that folder. |
| A download timeout or “too many requests” | Check your connection. For a Docker Hub limit, sign in to Docker Desktop or wait for the limit to reset, then repeat step 3. |
| “Port is already allocated” | Another program is using 18080. Ask whoever manages that program to stop it or use the alternative-port instructions in the [operator guide](https://github.com/phpledger/phpledger/blob/master/docs/CONTAINER.md#use-a-different-local-port). |
| “Access denied” at Check database | Use host `db` and user `phpledger`. Recopy the password after `PL_DB_PASSWORD=`; do not use the line containing `ROOT`. Keep the original `.env` file. |

If you still need help, share the step number and error text with [PHP Ledger support](https://github.com/phpledger/phpledger/discussions). Do not attach `.env` or passwords. Avoid **Delete**, **Clean / Purge data**, or commands ending in `down -v`: those can remove saved records.

This recipe is for use on this computer. [Backups, upgrades and server deployment](https://github.com/phpledger/phpledger/blob/master/docs/CONTAINER.md) are covered separately. [Choose another installation](https://github.com/phpledger/phpledger/wiki/Getting-Started).
