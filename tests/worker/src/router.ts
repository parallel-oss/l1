import { IRequest, Router } from 'itty-router';

const router = Router();

declare type Env = {
	DB1: D1Database;
	DB2: D1Database;
	[key: string]: any;
}

router.post(
	'/api/client/v4/accounts/:account/d1/database/:database/query',
	async (request: IRequest, env: Env) => {
		const body = await request.json();
		let res: D1Result;

		try {
			const db = (env[request.params.database as string] ?? env.DB1) as D1Database;
			res = await db
				.prepare(body.sql)
				.bind(...(body.params || []))
				.all();
		} catch (e: any) {
			return new Response(JSON.stringify({
				errors: [
					{
						message: e.stack,
						code: e.message,
					},
				],
			}), {
				headers: { 'Content-Type': 'application/json' },
			});
		}

		return new Response(JSON.stringify({
			success: res.success,
			result: [res],
		}), {
			headers: {
				'Content-Type': 'application/json',
			},
		});
	},
);

// 404 for everything else
router.all('*', () => new Response('Not Found.', { status: 404 }));

export default router;
