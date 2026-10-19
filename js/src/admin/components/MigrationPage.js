import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Alert from 'flarum/common/components/Alert';

export default class MigrationPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);

    this.activeTab = 'backup';
    this.loading = false;
    this.backups = [];
    this.includeUploads = true;
    this.includeVendor = false;
    this.includeConfig = false;
    this.logs = [];

    // Migration settings
    this.uploadedFile = null;
    this.newUrl = '';
    this.dbHost = 'localhost';
    this.dbName = '';
    this.dbUser = '';
    this.dbPass = '';

    this.loadBackups();
    this.loadStatus();
    this.progress = null;
    this.pollingInterval = null;
  }

  onremove() {
    this.stopPolling();
  }

  startPolling() {
    if (this.pollingInterval) return;
    this.pollingInterval = setInterval(() => {
      this.loadStatus(true);
    }, 2000);
  }

  stopPolling() {
    if (this.pollingInterval) {
      clearInterval(this.pollingInterval);
      this.pollingInterval = null;
    }
  }

  loadStatus(silent = false) {
    if (!silent) this.loading = true;

    return app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/migration/status',
      })
      .then((response) => {
        if (response.success) {
          if (response.current_config) {
            const config = response.current_config;
            this.newUrl = this.newUrl || config.url;
            this.dbHost = this.dbHost === 'localhost' ? config.db_host : this.dbHost;
            this.dbName = this.dbName || config.db_name;
            this.dbUser = this.dbUser || config.db_user;
            this.hasDbPass = config.has_db_pass;
          }
          if (response.progress) {
            this.progress = response.progress;
            if (this.progress.status === 'complete' || this.progress.status === 'error' || this.progress.status === 'idle') {
              // Optionally stop polling if complete, but we might want to keep it until UI reacts
            }
          }
        }
      })
      .finally(() => {
        if (!silent) this.loading = false;
        m.redraw();
      });
  }

  content() {
    return (
      <div className="MigrationPage">
        <div className="container">
          <h2>{app.translator.trans('migration-tool.admin.title')}</h2>

          {/* Tabs */}
          <div className="MigrationTabs">
            <button
              className={'Button ' + (this.activeTab === 'backup' ? 'Button--primary' : '')}
              onclick={() => this.switchTab('backup')}
            >
              {app.translator.trans('migration-tool.admin.tabs.backup')}
            </button>
            <button
              className={'Button ' + (this.activeTab === 'migrate' ? 'Button--primary' : '')}
              onclick={() => this.switchTab('migrate')}
            >
              {app.translator.trans('migration-tool.admin.tabs.migrate')}
            </button>
            <button
              className={'Button ' + (this.activeTab === 'logs' ? 'Button--primary' : '')}
              onclick={() => this.switchTab('logs')}
            >
              {app.translator.trans('migration-tool.admin.tabs.logs')}
            </button>
          </div>

          {/* Tab Content */}
          <div className="MigrationContent">
            {this.renderProgressBar()}
            {this.activeTab === 'backup' && this.backupTab()}
            {this.activeTab === 'migrate' && this.migrateTab()}
            {this.activeTab === 'logs' && this.logsTab()}
          </div>
        </div>
      </div>
    );
  }

  renderProgressBar() {
    if (!this.progress || this.progress.status === 'idle' || this.progress.status === '') return null;

    const progress = this.progress;
    const isComplete = progress.status === 'complete';
    const isError = progress.status === 'error';

    return (
      <div className="MigrationProgress" style="margin: 20px 0; padding: 15px; background: #f8f8f8; border-radius: 5px; border-left: 4px solid #4d698e;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: bold;">
          <span>{progress.label}</span>
          <span>{progress.percent}%</span>
        </div>
        <div className="Progress-bar" style="background: #eee; height: 12px; border-radius: 6px; overflow: hidden;">
          <div
            style={{
              width: progress.percent + '%',
              height: '100%',
              background: isError ? '#d83e3e' : (isComplete ? '#469b4e' : '#4d698e'),
              transition: 'width 0.4s ease'
            }}
          ></div>
        </div>
        {isComplete && (
          <div style="margin-top: 10px; text-align: right;">
            <Button className="Button Button--link" onclick={() => this.resetProgress()}>✕ Dismiss</Button>
          </div>
        )}
      </div>
    );
  }

  resetProgress() {
    this.progress = null;
    app.request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/migration/progress/reset',
    });
    m.redraw();
  }

  backupTab() {
    return (
      <div className="BackupTab">
        <div className="Form">
          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.backup.settings')}</label>

            <Switch
              state={this.includeUploads}
              onchange={(value) => {
                this.includeUploads = value;
              }}
            >
              {app.translator.trans('migration-tool.admin.backup.include_uploads')}
            </Switch>

            <Switch
              state={this.includeVendor}
              onchange={(value) => {
                this.includeVendor = value;
              }}
            >
              {app.translator.trans('migration-tool.admin.backup.include_vendor')}
            </Switch>

            <Switch
              state={this.includeConfig}
              onchange={(value) => {
                this.includeConfig = value;
              }}
            >
              {app.translator.trans('migration-tool.admin.backup.include_config')}
            </Switch>

            <p className="helpText">
              {app.translator.trans('migration-tool.admin.backup.help')}
            </p>
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary"
              onclick={() => this.createBackup()}
              loading={this.loading}
            >
              {app.translator.trans('migration-tool.admin.backup.create_button')}
            </Button>
          </div>
        </div>

        <hr />

        <div className="BackupList">
          <h3>{app.translator.trans('migration-tool.admin.backup.list_title')}</h3>

          {this.loading ? (
            <LoadingIndicator />
          ) : (
            <table className="Table">
              <thead>
                <tr>
                  <th>{app.translator.trans('migration-tool.admin.backup.filename')}</th>
                  <th>{app.translator.trans('migration-tool.admin.backup.size')}</th>
                  <th>{app.translator.trans('migration-tool.admin.backup.created')}</th>
                  <th>{app.translator.trans('migration-tool.admin.backup.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {this.backups.length === 0 ? (
                  <tr>
                    <td colSpan="4" className="text-center">
                      {app.translator.trans('migration-tool.admin.backup.no_backups')}
                    </td>
                  </tr>
                ) : (
                  this.backups.map((backup) => (
                    <tr>
                      <td>{backup.filename}</td>
                      <td>{backup.size_formatted}</td>
                      <td>{backup.created_formatted}</td>
                      <td>
                        <Button
                          className="Button Button--link"
                          onclick={() => this.downloadBackup(backup.filename)}
                        >
                          {app.translator.trans('migration-tool.admin.backup.download')}
                        </Button>
                        {' '}
                        <Button
                          className="Button Button--link Button--danger"
                          onclick={() => this.deleteBackup(backup.filename)}
                        >
                          {app.translator.trans('migration-tool.admin.backup.delete')}
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    );
  }

  migrateTab() {
    return (
      <div className="MigrateTab">
        <div className="Alert Alert--warning">
          <strong>{app.translator.trans('migration-tool.admin.migrate.warning')}</strong>
          <p>{app.translator.trans('migration-tool.admin.migrate.warning_text')}</p>
        </div>

        <div className="Form">
          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.migrate.upload_label')}</label>
            <input
              type="file"
              accept=".zip"
              onchange={(e) => this.handleFileUpload(e)}
              className="FormControl"
            />
            {this.uploadedFile && (
              <div className="helpText">
                {app.translator.trans('migration-tool.admin.migrate.uploaded')}: {this.uploadedFile.filename}
              </div>
            )}
          </div>

          <hr />

          <h3>{app.translator.trans('migration-tool.admin.migrate.config_title')}</h3>

          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.migrate.new_url')}</label>
            <input
              type="text"
              className="FormControl"
              value={this.newUrl}
              oninput={(e) => {
                this.newUrl = e.target.value;
              }}
              placeholder="https://your-new-domain.com"
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.migrate.db_host')}</label>
            <input
              type="text"
              className="FormControl"
              value={this.dbHost}
              oninput={(e) => {
                this.dbHost = e.target.value;
              }}
              placeholder="localhost"
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.migrate.db_name')}</label>
            <input
              type="text"
              className="FormControl"
              value={this.dbName}
              oninput={(e) => {
                this.dbName = e.target.value;
              }}
              placeholder="flarum_db"
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.migrate.db_user')}</label>
            <input
              type="text"
              className="FormControl"
              value={this.dbUser}
              oninput={(e) => {
                this.dbUser = e.target.value;
              }}
              placeholder="root"
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('migration-tool.admin.migrate.db_pass')}</label>
            <input
              type="password"
              className="FormControl"
              value={this.dbPass}
              oninput={(e) => {
                this.dbPass = e.target.value;
              }}
              placeholder="********"
              autocomplete="new-password"
            />
            {this.hasDbPass && (
              <div className="helpText">{app.translator.trans('migration-tool.admin.migrate.db_pass_help')}</div>
            )}
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary"
              onclick={() => this.startMigration()}
              loading={this.loading}
              disabled={!this.uploadedFile}
            >
              {app.translator.trans('migration-tool.admin.migrate.start_button')}
            </Button>
            {' '}
            <Button
              className="Button Button--danger"
              onclick={() => this.cleanupOldFiles()}
              loading={this.loading}
            >
              {app.translator.trans('migration-tool.admin.migrate.cleanup_button')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  cleanupOldFiles() {
    if (!confirm(app.translator.trans('migration-tool.admin.migrate.cleanup_confirm'))) {
      return;
    }

    this.loading = true;
    this.startPolling();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/migration/cleanup',
      })
      .then((response) => {
        if (response.success) {
          app.alerts.show(Alert, { type: 'success' }, app.translator.trans('migration-tool.admin.migrate.cleanup_success'));
        }
      })
      .finally(() => {
        this.loading = false;
        this.stopPolling();
        this.loadStatus(true);
        m.redraw();
      });
  }

  logsTab() {
    return (
      <div className="LogsTab">
        <div className="Form-group">
          <Button
            className="Button"
            onclick={() => this.loadLogs()}
            loading={this.loading}
          >
            {app.translator.trans('migration-tool.admin.logs.refresh')}
          </Button>
        </div>

        <div className="LogsContent">
          <pre style="background: #2d2d2d; color: #f8f8f8; padding: 15px; border-radius: 5px; max-height: 500px; overflow-y: auto;">
            {this.logs.length === 0 ? (
              app.translator.trans('migration-tool.admin.logs.no_logs')
            ) : (
              this.logs.join('')
            )}
          </pre>
        </div>
      </div>
    );
  }

  switchTab(tab) {
    if (this.progress && this.progress.status === 'complete') {
      this.resetProgress();
    }

    this.activeTab = tab;
    if (tab === 'backup') {
      this.loadBackups();
    } else if (tab === 'migrate') {
      this.loadStatus();
    } else if (tab === 'logs') {
      this.loadLogs();
    }
  }

  createBackup() {
    this.loading = true;
    this.startPolling();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/migration/backup/create',
        body: {
          include_uploads: this.includeUploads,
          include_vendor: this.includeVendor,
          include_config: this.includeConfig,
        },
      })
      .then((response) => {
        if (response.success) {
          app.alerts.show(Alert, { type: 'success' }, app.translator.trans('migration-tool.admin.backup.success'));
          this.loadBackups();
        } else {
          app.alerts.show(Alert, { type: 'error' }, response.error);
        }
      })
      .catch((error) => {
        app.alerts.show(Alert, { type: 'error' }, error.message);
      })
      .finally(() => {
        this.loading = false;
        this.stopPolling();
        this.loadStatus(true); // Final status check
        m.redraw();
      });
  }

  loadBackups() {
    this.loading = true;

    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/migration/backup/list',
      })
      .then((response) => {
        if (response.success) {
          this.backups = response.backups;
        }
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  downloadBackup(filename) {
    window.open(app.forum.attribute('apiUrl') + '/migration/backup/download?filename=' + encodeURIComponent(filename), '_blank');
  }

  deleteBackup(filename) {
    if (!confirm(app.translator.trans('migration-tool.admin.backup.delete_confirm'))) {
      return;
    }

    app
      .request({
        method: 'DELETE',
        url: app.forum.attribute('apiUrl') + '/migration/backup/delete?filename=' + encodeURIComponent(filename),
      })
      .then((response) => {
        if (response.success) {
          app.alerts.show(Alert, { type: 'success' }, app.translator.trans('migration-tool.admin.backup.deleted'));
          this.loadBackups();
        }
      });
  }

  handleFileUpload(e) {
    const file = e.target.files[0];
    if (!file) return;

    this.loading = true;

    const formData = new FormData();
    formData.append('backup', file);

    fetch(app.forum.attribute('apiUrl') + '/migration/upload', {
      method: 'POST',
      body: formData,
      headers: {
        'X-CSRF-Token': app.session.csrfToken,
      },
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          this.uploadedFile = data;
          app.alerts.show(Alert, { type: 'success' }, app.translator.trans('migration-tool.admin.migrate.upload_success'));
        } else {
          app.alerts.show(Alert, { type: 'error' }, data.error);
        }
      })
      .catch((error) => {
        app.alerts.show(Alert, { type: 'error' }, error.message);
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  startMigration() {
    if (!confirm(app.translator.trans('migration-tool.admin.migrate.confirm'))) {
      return;
    }

    this.loading = true;
    this.startPolling();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/migration/migrate',
        body: {
          filename: this.uploadedFile.filename,
          new_url: this.newUrl,
          db_host: this.dbHost,
          db_name: this.dbName,
          db_user: this.dbUser,
          db_pass: this.dbPass,
        },
      })
      .then((response) => {
        if (response.success) {
          app.alerts.show(Alert, { type: 'success' }, app.translator.trans('migration-tool.admin.migrate.success'));
          (response.warnings || []).forEach((warning) => app.alerts.show(Alert, { type: 'warning' }, warning));
          this.switchTab('logs');
        } else {
          app.alerts.show(Alert, { type: 'error' }, response.error);
        }
      })
      .catch((error) => {
        app.alerts.show(Alert, { type: 'error' }, error.message);
      })
      .finally(() => {
        this.loading = false;
        this.stopPolling();
        this.loadStatus(true);
        m.redraw();
      });
  }

  loadLogs() {
    this.loading = true;

    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/migration/logs',
      })
      .then((response) => {
        if (response.success) {
          this.logs = response.logs;
        }
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
