import { build, context } from 'esbuild';

const isWatch = process.argv.includes('--watch');

const options = {
    entryPoints: ['src/js/gaitcha.js'],
    bundle: true,
    format: 'iife',
    globalName: 'Gaitcha',
    outfile: 'dist/gaitcha.min.js',
    minify: !isWatch,
    sourcemap: isWatch,
    target: ['es2020'],
};

if (isWatch) {
    const ctx = await context(options);
    await ctx.watch();
    console.log('Watching for changes...');
} else {
    await build(options);
    console.log('Built dist/gaitcha.min.js');
}
