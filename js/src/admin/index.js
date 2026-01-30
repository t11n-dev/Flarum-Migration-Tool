import app from 'flarum/admin/app';
import MigrationPage from './components/MigrationPage';

app.initializers.add('nauhyuh/flarum-migration-tool', () => {
  app.extensionData
    .for('nauhyuh-migration-tool')
    .registerPage(MigrationPage);
});
